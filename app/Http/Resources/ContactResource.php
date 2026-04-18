<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Contact
 */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phone_number,
            'name' => $this->name,
            'profile_name' => $this->profile_name,
            'wa_id' => $this->wa_id,
            'opt_in' => $this->opt_in,
            'created_via' => $this->created_via,
            'created_at' => $this->created_at,
        ];
    }
}

