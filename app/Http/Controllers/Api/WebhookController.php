<?php

namespace App\Http\Controllers\Api;

use App\Events\NewMessageReceived;
use App\Events\MessageStatusUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookLog;
use App\Services\FlowEngine;
use App\Services\MetaWhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private MetaWhatsAppService $metaService,
        private FlowEngine $flowEngine
    ) {}

    /**
     * Meta signs the raw POST body with the App Secret (Developer App → Settings → Basic → App secret).
     * It is not the WhatsApp access token or the verify token.
     */
    private function isValidSignature(Request $request): bool
    {
        $settings = $this->metaService->getSettings();
        $secret = $settings?->app_secret;
        $secretTrim = is_string($secret) ? trim($secret) : '';

        // If no app secret is configured, don't block webhooks (dev only — set secret in production).
        if ($secretTrim === '') {
            return true;
        }

        $signature = trim((string) $request->header('X-Hub-Signature-256'));
        if ($signature === '' || ! preg_match('/^sha256=(.+)$/i', $signature, $m)) {
            return false;
        }

        $providedHex = strtolower($m[1]);
        $rawBody = $request->getContent();
        $expectedHex = hash_hmac('sha256', $rawBody, $secretTrim);

        return hash_equals($expectedHex, $providedHex);
    }

    public function verify(Request $request)
    {
        // Meta uses dotted query keys; PHP converts dots to underscores (hub.mode → hub_mode, etc.).
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode') ?? '';
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token') ?? $request->query('hub_token') ?? '';
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge') ?? '';

        WebhookLog::create([
            'event_type' => 'webhook_verify',
            'direction' => 'inbound',
            'from_number' => $request->ip(),
            'payload' => $request->query(),
            'http_status' => 200,
        ]);

        $result = $this->metaService->verifyWebhook($mode, $token, $challenge);

        if ($result['success']) {
            return response($result['challenge'], 200);
        }

        return response('Verification failed', 403);
    }

    public function handle(Request $request)
    {
        if (! $this->isValidSignature($request)) {
            $settings = $this->metaService->getSettings();
            $sigHeader = trim((string) $request->header('X-Hub-Signature-256'));

            WebhookLog::create([
                'event_type' => 'webhook_signature_invalid',
                'direction' => 'inbound',
                'from_number' => $request->ip(),
                'payload' => [
                    'raw_body_length' => strlen($request->getContent()),
                    'has_signature_header' => $request->hasHeader('X-Hub-Signature-256'),
                    'signature_prefix_ok' => $sigHeader !== '' && preg_match('/^sha256=/i', $sigHeader) === 1,
                    'meta_app_id' => $settings?->app_id,
                    'app_secret_configured' => is_string($settings?->app_secret) && trim((string) $settings->app_secret) !== '',
                    'hint' => 'app_secret must match Meta App Secret (App settings → Basic).',
                ],
                'http_status' => 403,
            ]);

            Log::warning('WhatsApp webhook signature verification failed', [
                'raw_body_length' => strlen($request->getContent()),
                'meta_app_id' => $settings?->app_id,
            ]);

            return response()->json(['error' => 'Invalid webhook signature'], 403);
        }

        $payload = $request->all();
        Log::info('WhatsApp Webhook received:', $payload);

        $entry = $payload['entry'][0] ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value = $changes['value'] ?? null;

        if (! $value) {
            return response()->json(['status' => 'ignored']);
        }

        if (isset($value['metadata'])) {
            $this->handleVerification($value);
            WebhookLog::create([
                'event_type' => 'webhook_verified',
                'direction' => 'inbound',
                'payload' => $value['metadata'],
                'http_status' => 200,
            ]);
        }

        if (isset($value['messages'])) {
            foreach ($value['messages'] as $msg) {
                $this->handleIncomingMessage($value, $msg);
                WebhookLog::create([
                    'event_type' => 'message_received',
                    'direction' => 'inbound',
                    'from_number' => $msg['from'] ?? null,
                    'message_id' => $msg['id'] ?? null,
                    'payload' => $msg,
                    'http_status' => 200,
                ]);
            }
        }

        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                $this->handleStatusUpdate($status);
                WebhookLog::create([
                    'event_type' => 'message_status',
                    'direction' => 'outbound',
                    'message_id' => $status['id'] ?? null,
                    'status' => $status['status'] ?? null,
                    'payload' => $status,
                    'http_status' => 200,
                ]);
            }
        }

        return response()->json(['status' => 'processed']);
    }

    private function handleVerification(array $metadata): void
    {
        $settings = $this->metaService->getSettings();
        if ($settings) {
            $settings->update([
                'webhook_verified' => true,
                'webhook_subscriptions' => ['messages', 'statuses'],
            ]);
        }
    }

    private function handleIncomingMessage(array $value, array $msg): void
    {
        $phoneNumber = $msg['from'];
        $waId = $msg['id'];
        $metaMessageId = is_string($waId) ? $waId : (is_scalar($waId) ? (string) $waId : '');
        if ($metaMessageId === '') {
            return;
        }

        // Meta may retry webhooks; skip duplicate deliveries by message id.
        if (Message::query()->where('meta_message_id', $metaMessageId)->exists()) {
            return;
        }

        // Meta sends unix timestamps (UTC). Keep all window logic in UTC.
        $timestamp = isset($msg['timestamp'])
            ? Carbon::createFromTimestampUTC((int) $msg['timestamp'])
            : now('UTC');
        $expiresAt = $timestamp->copy()->addHours(24);

        $contact = Contact::firstOrCreate(
            ['phone_number' => $phoneNumber],
            ['wa_id' => $waId, 'created_via' => 'whatsapp_inbound']
        );

        $profileName = $this->extractWhatsAppProfileName($value['contacts'] ?? [], $phoneNumber);
        if ($profileName !== null) {
            $updates = ['profile_name' => $profileName];
            if (! $contact->name) {
                $updates['name'] = $profileName;
            }
            $contact->update($updates);
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id],
            [
                'wa_conversation_id' => $waId,
                'window_expires_at' => $expiresAt,
            ]
        );

        $conversation->update([
            'last_message_at' => $timestamp,
            'window_expires_at' => $expiresAt,
        ]);

        // Prefer Meta's explicit type when present.
        $type = is_string($msg['type'] ?? null) ? (string) $msg['type'] : 'text';
        $content = null;
        $mediaUrl = null;
        $mediaType = null;
        $mediaId = null;
        $interactivePayload = null;

        if (isset($msg['text'])) {
            $type = 'text';
            $content = $msg['text']['body'];
        } elseif (isset($msg['interactive'])) {
            $type = 'text';
            $interactivePayload = $msg['interactive'];
            $interactiveType = $msg['interactive']['type'] ?? null;

            if ($interactiveType === 'button_reply') {
                $title = $msg['interactive']['button_reply']['title'] ?? null;
                $content = $title ? "Button reply: {$title}" : 'Button reply';
            } elseif ($interactiveType === 'list_reply') {
                $title = $msg['interactive']['list_reply']['title'] ?? null;
                $desc = $msg['interactive']['list_reply']['description'] ?? null;
                $content = $title ? ($desc ? "List reply: {$title} — {$desc}" : "List reply: {$title}") : 'List reply';
            } else {
                $content = 'Interactive reply';
            }
        } elseif (isset($msg['image'])) {
            $type = 'image';
            $mediaId = $msg['image']['id'] ?? null;
            $mediaType = $msg['image']['mime_type'] ?? null;
            $content = $msg['image']['caption'] ?? null;
        } elseif (isset($msg['video'])) {
            $type = 'video';
            $mediaId = $msg['video']['id'] ?? null;
            $mediaType = $msg['video']['mime_type'] ?? null;
            $content = $msg['video']['caption'] ?? null;
        } elseif (isset($msg['audio'])) {
            $type = 'audio';
            $mediaId = $msg['audio']['id'] ?? null;
            $mediaType = $msg['audio']['mime_type'] ?? null;
        } elseif (isset($msg['document'])) {
            $type = 'document';
            $mediaId = $msg['document']['id'] ?? null;
            $mediaType = $msg['document']['mime_type'] ?? null;
            $content = $msg['document']['filename'] ?? null;
        } elseif (isset($msg['sticker'])) {
            $type = 'sticker';
            $mediaId = $msg['sticker']['id'] ?? null;
            $mediaType = $msg['sticker']['mime_type'] ?? null;
        } elseif ((isset($msg['contacts']) && is_array($msg['contacts'])) || (($msg['type'] ?? '') === 'contacts')) {
            $type = 'text';
            $rawContacts = (isset($msg['contacts']) && is_array($msg['contacts'])) ? $msg['contacts'] : [];
            $items = $this->normalizeSharedContactItems($rawContacts);
            if ($items === [] && (($msg['type'] ?? '') === 'contacts')) {
                $items[] = [
                    'display_name' => 'Shared contact',
                    'phone' => null,
                    'wa_id' => null,
                    'emails' => [],
                    'phones' => [],
                ];
            }
            $interactivePayload = ['type' => 'contacts', 'items' => $items];
            $names = [];
            foreach ($items as $item) {
                $label = $item['display_name'] ?? '';
                $label = is_string($label) ? trim($label) : '';
                if ($label !== '') {
                    $names[] = $label;
                    continue;
                }
                $p = $item['phone'] ?? $item['wa_id'] ?? null;
                if (is_string($p) && trim($p) !== '') {
                    $names[] = trim($p);
                }
            }
            $content = $names !== []
                ? ('Shared contact'.(count($names) > 1 ? 's' : '').': '.implode(', ', array_slice($names, 0, 5)))
                : 'Shared contact';
        } elseif (isset($msg['location'])) {
            $type = 'location';
            $loc = $msg['location'];
            $lines = array_values(array_filter([
                $loc['name'] ?? null,
                $loc['address'] ?? null,
                isset($loc['latitude'], $loc['longitude'])
                    ? trim((string) $loc['latitude']).', '.trim((string) $loc['longitude'])
                    : null,
            ], fn ($v) => $v !== null && trim((string) $v) !== ''));
            $content = $lines !== [] ? implode("\n", $lines) : null;
            $interactivePayload = ['type' => 'location', 'location' => $loc];
        }

        // Meta can send types we do not model yet; coerce to text so MySQL ENUM/VARCHAR never breaks.
        $persistableTypes = ['text', 'template', 'image', 'audio', 'video', 'document', 'sticker', 'location'];
        if (! in_array($type, $persistableTypes, true)) {
            $metaType = $type;
            $type = 'text';
            if ($content === null || trim((string) $content) === '') {
                $content = '['.strtoupper((string) $metaType).']';
            }
        }

        // Ensure we always have a displayable fallback content.
        if ($content === null || trim((string) $content) === '') {
            $content = match ($type) {
                'image' => '[Image]',
                'video' => '[Video]',
                'audio' => '[Audio]',
                'document' => '[Document]',
                'sticker' => '[Sticker]',
                'interactive' => '[Interactive]',
                'location' => '[Location]',
                default => '[Message]',
            };
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => $metaMessageId,
            'direction' => 'inbound',
            'sender_kind' => 'contact',
            'sent_by_user_id' => null,
            'type' => $type,
            'content' => $content,
            'interactive_payload' => $interactivePayload,
            'media_id' => $mediaId,
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'status' => 'received',
            'sent_at' => $timestamp,
        ]);

        $conversation->increment('unread_inbound_count');

        // Default: run in-process so bot replies are not delayed by an idle queue worker.
        // Set WHATSAPP_PROCESS_INCOMING_SYNC=false and run `php artisan queue:work` for async.
        if (config('services.whatsapp.process_incoming_sync', true)) {
            ProcessIncomingMessage::dispatchSync($message->id);
        } else {
            ProcessIncomingMessage::dispatch($message->id);
        }
    }

    /**
     * Flatten Meta's vCard-style contact objects into stable fields for storage, broadcasts, and UI.
     *
     * @param  array<int, mixed>  $rawContacts
     * @return array<int, array{display_name: string, phone: ?string, wa_id: ?string, emails: array<int, string>, phones: array<int, array{phone: ?string, wa_id: ?string}>}>
     */
    private function normalizeSharedContactItems(array $rawContacts): array
    {
        $out = [];
        foreach ($rawContacts as $c) {
            if (! is_array($c)) {
                continue;
            }
            $nameBlock = $c['name'] ?? null;
            $display = '';
            if (is_string($nameBlock) && trim($nameBlock) !== '') {
                $display = trim($nameBlock);
            } elseif (is_array($nameBlock)) {
                $fn = $nameBlock['formatted_name'] ?? null;
                if (is_string($fn) && trim($fn) !== '') {
                    $display = trim($fn);
                } else {
                    $parts = array_filter([
                        $nameBlock['prefix'] ?? null,
                        $nameBlock['first_name'] ?? null,
                        $nameBlock['middle_name'] ?? null,
                        $nameBlock['last_name'] ?? null,
                        $nameBlock['suffix'] ?? null,
                    ], fn ($p) => is_string($p) && trim($p) !== '');
                    if ($parts !== []) {
                        $display = trim(implode(' ', array_map(static fn ($p) => trim((string) $p), $parts)));
                    }
                }
            }
            if ($display === '') {
                $org = $c['org'] ?? null;
                if (is_array($org) && isset($org['company']) && is_string($org['company']) && trim($org['company']) !== '') {
                    $display = trim($org['company']);
                }
            }

            $phonesRaw = $c['phones'] ?? [];
            if ($phonesRaw instanceof \stdClass) {
                $phonesRaw = (array) $phonesRaw;
            }
            $phoneRows = [];
            if (is_array($phonesRaw) && $phonesRaw !== []) {
                foreach (array_values($phonesRaw) as $ph) {
                    if (! is_array($ph)) {
                        continue;
                    }
                    $num = $ph['phone'] ?? null;
                    $wa = $ph['wa_id'] ?? null;
                    $num = is_string($num) ? trim($num) : null;
                    $wa = is_string($wa) ? trim($wa) : null;
                    if ($num === null && $wa === null) {
                        continue;
                    }
                    if ($num === null && $wa !== null) {
                        $num = $wa;
                    }
                    $phoneRows[] = ['phone' => $num, 'wa_id' => $wa];
                }
            }

            $emailsRaw = $c['emails'] ?? [];
            $emails = [];
            if (is_array($emailsRaw)) {
                foreach (array_values($emailsRaw) as $em) {
                    if (is_array($em) && isset($em['email']) && is_string($em['email']) && trim($em['email']) !== '') {
                        $emails[] = trim($em['email']);
                    }
                }
            }

            $primaryPhone = $phoneRows[0]['phone'] ?? null;
            $primaryWa = $phoneRows[0]['wa_id'] ?? null;

            if ($display === '' && $primaryPhone === null && $primaryWa === null && $emails === []) {
                continue;
            }

            $out[] = [
                'display_name' => $display,
                'phone' => $primaryPhone,
                'wa_id' => $primaryWa,
                'emails' => $emails,
                'phones' => $phoneRows,
            ];
        }

        if ($out === [] && $rawContacts !== []) {
            $out[] = [
                'display_name' => 'Shared contact',
                'phone' => null,
                'wa_id' => null,
                'emails' => [],
                'phones' => [],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     */
    private function extractWhatsAppProfileName(array $contacts, string $phoneNumber): ?string
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';
        if ($digits === '') {
            return null;
        }

        foreach ($contacts as $entry) {
            $waId = isset($entry['wa_id']) ? (string) $entry['wa_id'] : '';
            $entryDigits = preg_replace('/\D+/', '', $waId) ?? '';
            if ($entryDigits !== $digits) {
                continue;
            }

            $name = $entry['profile']['name'] ?? null;
            if (is_string($name)) {
                $trimmed = trim($name);

                return $trimmed !== '' ? $trimmed : null;
            }
        }

        return null;
    }

    private function handleStatusUpdate(array $status): void
    {
        $metaMessageId = $status['id'] ?? null;
        $messageStatus = $status['status'] ?? null;

        if (! $metaMessageId || ! is_string($messageStatus) || $messageStatus === '') {
            return;
        }

        $message = Message::where('meta_message_id', $metaMessageId)->first();
        if (! $message) {
            return;
        }

        $updates = [];
        switch ($messageStatus) {
            case 'sent':
                if ($message->sent_at === null) {
                    $updates['sent_at'] = now();
                }
                break;
            case 'delivered':
                if ($message->delivered_at === null) {
                    $updates['delivered_at'] = now();
                }
                break;
            case 'read':
                if ($message->read_at === null) {
                    $updates['read_at'] = now();
                }
                break;
            case 'failed':
                if ($message->status !== 'failed') {
                    $updates['status'] = 'failed';
                }
                break;
        }

        if ($updates === []) {
            return;
        }

        $message->update($updates);
        $message->refresh();
        event(new MessageStatusUpdated($message));
    }
}
