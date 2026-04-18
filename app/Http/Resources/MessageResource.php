<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Message
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = (string) ($request->header('X-Timezone') ?: config('app.display_timezone', 'UTC'));

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'contact_id' => $this->contact_id,
            'meta_message_id' => $this->meta_message_id,
            'direction' => $this->direction,
            'sender_kind' => $this->sender_kind,
            'sent_by_user_id' => $this->sent_by_user_id,
            'sent_by_user' => UserResource::make($this->whenLoaded('sentByUser')),
            'type' => $this->type,
            'content' => $this->content,
            'template_name' => $this->template_name,
            'template_components' => $this->template_components,
            'interactive_payload' => $this->interactive_payload,
            'media_id' => $this->media_id,
            'media_type' => $this->media_type,
            'media_download_url' => $this->media_id ? url("/api/messages/{$this->id}/media") : null,
            'status' => $this->status,
            'sent_at' => $this->sent_at,
            'sent_at_local' => $this->sent_at?->copy()->timezone($tz)->toISOString(),
            'created_at' => $this->created_at,
            'created_at_local' => $this->created_at?->copy()->timezone($tz)->toISOString(),
        ];
    }
}

