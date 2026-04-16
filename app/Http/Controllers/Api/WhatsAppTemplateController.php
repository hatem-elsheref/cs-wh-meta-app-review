<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Integrations\Whatsapp\MetaSender;
use Illuminate\Http\Request;

class WhatsAppTemplateController extends Controller
{
    public function __construct(private MetaSender $sender) {}

    public function send(Request $request)
    {
        $data = $request->validate([
            'phone_number' => 'required|string',
            'template' => 'required|string',
            'params' => 'nullable|array',
            'params.*.name' => 'required_with:params|string',
            'params.*.value' => 'nullable',
        ]);

        $result = $this->sender->sendTemplate($data['phone_number'], $data['template'], $data['params'] ?? []);

        $messageId = $this->persist($data['phone_number'], $data['template'], $data['params'] ?? [], $result);

        return response()->json([
            'status' => (bool) ($result['status'] ?? false),
            'message_id' => $messageId,
            'result' => $result,
        ], ($result['status'] ?? false) ? 200 : 422);
    }

    public function sendMultiple(Request $request)
    {
        $data = $request->validate([
            'phone_numbers' => 'required|array|min:1',
            'phone_numbers.*' => 'required|string',
            'template' => 'required|string',
            'params' => 'nullable|array',
            'params.*.name' => 'required_with:params|string',
            'params.*.value' => 'nullable',
        ]);

        $result = $this->sender->sendTemplateMultipleRecipients($data['phone_numbers'], $data['template'], $data['params'] ?? []);

        $saved = [];
        foreach ($result['results'] ?? [] as $phone => $one) {
            $saved[$phone] = $this->persist((string) $phone, $data['template'], $data['params'] ?? [], $one);
        }

        return response()->json([
            'status' => (bool) ($result['status'] ?? false),
            'saved_message_ids' => $saved,
            'result' => $result,
        ], ($result['status'] ?? false) ? 200 : 207);
    }

    /**
     * Persist outbound template send to chat history.
     *
     * @param  array<int, array{name?:string|int, value?:mixed}>  $params
     */
    private function persist(string $phone, string $template, array $params, array $sendResult): int
    {
        $contact = Contact::query()->firstOrCreate(['phone_number' => $phone], ['opt_in' => true]);
        $conversation = Conversation::query()->firstOrCreate(
            ['contact_id' => $contact->id],
            ['status' => 'open', 'window_expires_at' => null]
        );

        $conversation->update(['last_message_at' => now()]);

        $metaId = null;
        if (($sendResult['status'] ?? false) && is_array($sendResult['response'] ?? null)) {
            $metaId = $sendResult['response']['messages'][0]['id'] ?? null;
        }

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => is_string($metaId) ? $metaId : null,
            'direction' => 'outbound',
            'type' => 'template',
            'content' => $template,
            'template_name' => $template,
            'template_components' => [
                [
                    'type' => 'body',
                    'parameters' => array_map(fn ($p) => [
                        'type' => 'text',
                        'text' => (string) ($p['value'] ?? ''),
                    ], $params),
                ],
            ],
            'status' => ($sendResult['status'] ?? false) ? 'sent' : 'failed',
            'sent_at' => now(),
        ]);

        return (int) $msg->id;
    }
}

