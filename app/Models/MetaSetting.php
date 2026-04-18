<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Strip pasted .env syntax (ACCESS_TOKEN=...) and "Bearer " from WhatsApp Graph tokens.
     */
    public static function normalizeAccessToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim($value);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^ACCESS_TOKEN\s*=\s*(.+)$/is', $t, $m)) {
            $t = trim($m[1]);
        }
        if (preg_match('/^Bearer\s+(.+)$/is', $t, $m)) {
            $t = trim($m[1]);
        }
        $t = trim($t, " \t\n\r\0\x0B\"'");

        return $t !== '' ? $t : null;
    }

    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::normalizeAccessToken($value),
            set: function (?string $value) {
                $normalized = self::normalizeAccessToken($value);

                // DB column is non-null in some environments; empty means "no token".
                return $normalized === null ? '' : $normalized;
            },
        );
    }

    public static function getSettings(): self
    {
        return self::first() ?? new self;
    }

    public function getWebhookUrlAttribute(?string $value): ?string
    {
        return $value;
    }
}
