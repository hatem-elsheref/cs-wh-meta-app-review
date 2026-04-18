<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'api_key',
        'base_url',
        'default_language',
        'default_tone',
        'system_prompt',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
    ];
}

