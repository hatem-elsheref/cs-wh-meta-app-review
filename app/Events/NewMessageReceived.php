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
        $conversation = $this->message->relationLoaded('conversation')
            ? $this->message->conversation
            : $this->message->conversation()->first();

        $remaining = null;
        if ($conversation?->window_expires_at) {
            $remaining = now('UTC')->diffInSeconds($conversation->window_expires_at, false);
        }

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'contact_id' => $this->message->contact_id,
            'content' => $this->message->content,
            'type' => $this->message->type,
            'direction' => $this->message->direction,
            'created_at' => $this->message->created_at->toIso8601String(),
            // Include 24h window state so UI can remove "expired" hint immediately.
            'window_open' => $conversation?->isWindowOpen(),
            'window_expires_at' => $conversation?->window_expires_at?->toIso8601String(),
            'window_remaining_seconds' => $remaining,
        ];
    }
}
