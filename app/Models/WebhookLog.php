<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'event_type',
        'direction',
        'from_number',
        'to_number',
        'message_id',
        'status',
        'payload',
        'http_status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}