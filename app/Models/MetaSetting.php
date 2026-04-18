<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaSetting extends Model
{
    protected $fillable = [
        'phone_number_id',
        'waba_id',
        'app_id',
        'app_secret',
        'access_token',
        'webhook_url',
        'verify_token',
        'webhook_verified',
        'webhook_subscriptions',
    ];

    protected $casts = [
        'webhook_verified' => 'boolean',
        'webhook_subscriptions' => 'array',
    ];

    public static function getSettings(): self
    {
        return self::first() ?? new self;
    }

    public function getWebhookUrlAttribute(?string $value): ?string
    {
        return $value;
    }
}
