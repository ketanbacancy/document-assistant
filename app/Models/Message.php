<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    // Bumps the parent Conversation's updated_at whenever a message is
    // created, so the conversation list can order by "most recently active"
    // the same way ChatGPT's history sidebar does.
    protected $touches = ['conversation'];

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'sources',
    ];

    protected $casts = [
        'sources' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
