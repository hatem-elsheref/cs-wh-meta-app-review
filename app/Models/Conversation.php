<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'contact_id',
        'wa_conversation_id',
        'last_message_at',
        'window_expires_at',
        'status',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'window_expires_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function isWindowOpen(): bool
    {
        return $this->window_expires_at && $this->window_expires_at->isFuture();
    }

    public function canSendFreeText(): bool
    {
        return $this->isWindowOpen();
    }

    public function mustUseTemplate(): bool
    {
        return ! $this->isWindowOpen();
    }

    public function refreshWindow(): void
    {
        $this->window_expires_at = now()->addHours(24);
        $this->save();
    }

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            if (! $conversation->window_expires_at) {
                $conversation->window_expires_at = null;
            }
        });
    }
}
