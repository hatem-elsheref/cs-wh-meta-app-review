<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationState extends Model
{
    protected $fillable = [
        'phone',
        'flow_id',
        'current_node_id',
        'variables',
        'message_history',
        'mode',
        'language',
        'mode_revert_at',
        'awaiting_input',
        'rating_pending',
        'session_started_at',
    ];

    protected $casts = [
        'variables' => 'array',
        'message_history' => 'array',
        'mode_revert_at' => 'datetime',
        'awaiting_input' => 'array',
        'rating_pending' => 'array',
        'session_started_at' => 'datetime',
    ];

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }
}

