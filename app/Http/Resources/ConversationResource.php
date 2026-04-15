<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = (string) ($request->header('X-Timezone') ?: config('app.display_timezone', 'UTC'));

        $remaining = null;
        if ($this->window_expires_at) {
            $remaining = now('UTC')->diffInSeconds($this->window_expires_at, false);
        }

        return [
            'id' => $this->id,
            'contact' => $this->whenLoaded('contact'),
            'status' => $this->status,
            'window_open' => $this->isWindowOpen(),
            'window_remaining_seconds' => $remaining,
            'last_message_at' => $this->last_message_at,
            'last_message_at_local' => $this->last_message_at?->copy()->timezone($tz)->toISOString(),
            'window_expires_at' => $this->window_expires_at,
            'window_expires_at_local' => $this->window_expires_at?->copy()->timezone($tz)->toISOString(),
        ];
    }
}

