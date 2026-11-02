<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'wa_message_id', 'direction', 'sender', 'recipient', 'author', 'body', 'type',
        'sent_at', 'status', 'edited', 'source',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'edited' => 'boolean'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isIncoming(): bool
    {
        return $this->direction === 'incoming';
    }
}
