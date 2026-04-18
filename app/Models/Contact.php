<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'phone_number',
        'name',
        'profile_name',
        'wa_id',
        'opt_in',
        'created_via',
    ];

    protected $casts = [
        'opt_in' => 'boolean',
    ];

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
