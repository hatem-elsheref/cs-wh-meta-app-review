<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('chat'),
        ];
    }

    public function broadcastWith(): array
    {
        $this->message->loadMissing('sentByUser');

        $conversation = $this->message->relationLoaded('conversation')
            ? $this->message->conversation
            : $this->message->conversation()->first();

        $remaining = null;
        if ($conversation?->window_expires_at) {
            $remaining = now('UTC')->diffInSeconds($conversation->window_expires_at, false);
        }

        $sentBy = $this->message->sentByUser;

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'contact_id' => $this->message->contact_id,
            'meta_message_id' => $this->message->meta_message_id,
            'content' => $this->message->content,
            'type' => $this->message->type,
            'direction' => $this->message->direction,
            'sender_kind' => $this->message->sender_kind,
            'sent_by_user_id' => $this->message->sent_by_user_id,
            'sent_by_user' => $sentBy
                ? ['id' => $sentBy->id, 'name' => $sentBy->name, 'email' => $sentBy->email]
                : null,
            'template_name' => $this->message->template_name,
            'template_components' => $this->message->template_components,
            'interactive_payload' => $this->message->interactive_payload,
            'media_id' => $this->message->media_id,
            'media_type' => $this->message->media_type,
            'media_download_url' => $this->message->media_id
                ? url("/api/messages/{$this->message->id}/media")
                : null,
            'status' => $this->message->status,
            'sent_at' => $this->message->sent_at?->toIso8601String(),
            'created_at' => $this->message->created_at->toIso8601String(),
            // Include 24h window state so UI can remove "expired" hint immediately.
            'window_open' => $conversation?->isWindowOpen(),
            'window_expires_at' => $conversation?->window_expires_at?->toIso8601String(),
            'window_remaining_seconds' => $remaining,
        ];
    }
}
