<?php

namespace App\Jobs;

use App\Events\NewMessageReceived;
use App\Models\Message;
use App\Services\FlowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId) {}

    public function handle(FlowEngine $flowEngine): void
    {
        $message = Message::find($this->messageId);
        if (! $message) {
            return;
        }

        event(new NewMessageReceived($message));

        $flowEngine->processIncoming($message->contact?->phone_number ?? '', [
            'type' => $message->type,
            'content' => $message->content,
            'interactive' => $message->interactive_payload,
            'timestamp' => ($message->sent_at ?? $message->created_at)?->toISOString(),
        ]);
    }
}

