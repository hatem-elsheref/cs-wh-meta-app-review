<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    protected $fillable = [
        'phone',
        'order_number',
        'rating',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
    ];
}

