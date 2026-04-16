<?php

namespace App\Jobs;

use App\Events\MessageStatusUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId) {}

    public function handle(MetaWhatsAppService $metaService): void
    {
        $message = Message::with(['conversation.contact'])->find($this->messageId);
        if (! $message || $message->direction !== 'outbound') {
            return;
        }

        $phone = $message->conversation?->contact?->phone_number;
        if (! $phone) {
            $message->update(['status' => 'failed']);
            event(new MessageStatusUpdated($message));
            return;
        }

        $result = ['success' => false, 'error' => 'Unknown'];

        if ($message->type === 'template') {
            $templateName = $message->template_name;
            if (! $templateName) {
                $message->update(['status' => 'failed']);
                event(new MessageStatusUpdated($message));
                return;
            }

            // MetaWhatsAppService::sendMessage(template) expects empty text body.
            $result = $metaService->sendMessage(
                $phone,
                '',
                $templateName,
                $message->template_components,
                null
            );
        } elseif (! empty($message->interactive_payload)) {
            if (! $message->interactive_payload) {
                $message->update(['status' => 'failed']);
                event(new MessageStatusUpdated($message));
                return;
            }

            $result = $metaService->sendInteractive($phone, $message->interactive_payload);
        } else {
            $result = $metaService->sendMessage($phone, (string) ($message->content ?? ''));
        }

        if (($result['success'] ?? false) === true) {
            $now = now();
            $message->update([
                'status' => 'sent',
                'meta_message_id' => $result['meta_message_id'] ?? $message->meta_message_id,
                'sent_at' => $message->sent_at ?? $now,
            ]);

            Conversation::whereKey($message->conversation_id)->update(['last_message_at' => $now]);
            event(new MessageStatusUpdated($message));

            return;
        }

        $message->update(['status' => 'failed']);
        event(new MessageStatusUpdated($message));
    }
}

