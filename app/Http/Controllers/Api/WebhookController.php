<?php

namespace App\Http\Controllers\Api;

use App\Events\NewMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookLog;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    private function isValidSignature(Request $request): bool
    {
        $settings = $this->metaService->getSettings();
        $secret = $settings?->app_secret;

        // If no app secret is configured, don't block webhooks.
        if (! $secret) {
            return true;
        }

        $signature = $request->header('X-Hub-Signature-256');
        if (! $signature || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode') ?? '';
        $token = $request->query('hub_token') ?? '';
        $challenge = $request->query('hub_challenge') ?? '';

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
            WebhookLog::create([
                'event_type' => 'webhook_signature_invalid',
                'direction' => 'inbound',
                'from_number' => $request->ip(),
                'payload' => $request->headers->all(),
                'http_status' => 403,
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
                $this->handleIncomingMessage($value['metadata'], $msg);
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

    private function handleIncomingMessage(array $metadata, array $msg): void
    {
        $phoneNumber = $msg['from'];
        $waId = $msg['id'];
        $timestamp = isset($msg['timestamp']) ? now()->createFromTimestamp((int) $msg['timestamp']) : now();
        $expiresAt = $timestamp->copy()->addHours(24);

        $contact = Contact::firstOrCreate(
            ['phone_number' => $phoneNumber],
            ['wa_id' => $waId]
        );

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

        $type = 'text';
        $content = null;
        $mediaUrl = null;
        $mediaType = null;

        if (isset($msg['text'])) {
            $content = $msg['text']['body'];
        } elseif (isset($msg['interactive'])) {
            $type = 'text';
            $content = json_encode($msg['interactive']);
        } elseif (isset($msg['image'])) {
            $type = 'image';
            $mediaUrl = $msg['image']['mime_type'] ?? null;
        } elseif (isset($msg['video'])) {
            $type = 'video';
            $mediaUrl = $msg['video']['mime_type'] ?? null;
        } elseif (isset($msg['audio'])) {
            $type = 'audio';
            $mediaUrl = $msg['audio']['mime_type'] ?? null;
        } elseif (isset($msg['document'])) {
            $type = 'document';
            $mediaUrl = $msg['document']['mime_type'] ?? null;
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => $waId,
            'direction' => 'inbound',
            'type' => $type,
            'content' => $content,
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'status' => 'received',
            'sent_at' => $timestamp,
        ]);

        event(new NewMessageReceived($message));
    }

    private function handleStatusUpdate(array $status): void
    {
        $metaMessageId = $status['id'] ?? null;
        $messageStatus = $status['status'] ?? null;

        if (! $metaMessageId) {
            return;
        }

        $message = Message::where('meta_message_id', $metaMessageId)->first();
        if (! $message) {
            return;
        }

        $updates = [];
        switch ($messageStatus) {
            case 'sent':
                $updates['sent_at'] = now();
                break;
            case 'delivered':
                $updates['delivered_at'] = now();
                break;
            case 'read':
                $updates['read_at'] = now();
                break;
            case 'failed':
                $updates['status'] = 'failed';
                break;
        }

        if (! empty($updates)) {
            $message->update($updates);
        }
    }
}
