<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'contact_id',
        'meta_message_id',
        'direction',
        'type',
        'content',
        'template_name',
        'template_components',
        'media_url',
        'media_type',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'template_components' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
