<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new Channel('chat')];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'status' => $this->message->status,
            'meta_message_id' => $this->message->meta_message_id,
            'sent_at' => $this->message->sent_at?->toIso8601String(),
            'delivered_at' => $this->message->delivered_at?->toIso8601String(),
            'read_at' => $this->message->read_at?->toIso8601String(),
        ];
    }
}

