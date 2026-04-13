<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flow extends Model
{
    protected $fillable = [
        'nodes_json',
        'edges_json',
    ];

    protected $casts = [
        'nodes_json' => 'array',
        'edges_json' => 'array',
    ];
}

