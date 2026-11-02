<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'chat_id', 'name', 'is_group', 'kind', 'unread_count', 'last_message_at', 'last_message_preview',
        'auto_reply_enabled', 'auto_reply_text', 'auto_reply_cooldown_seconds', 'last_auto_reply_at',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'auto_reply_enabled' => 'boolean',
            'last_message_at' => 'datetime',
            'last_auto_reply_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function autoReplyLogs(): HasMany
    {
        return $this->hasMany(AutoReplyLog::class);
    }

    public function displayName(): string
    {
        return $this->name ?: preg_replace('/@.*$/', '', $this->chat_id);
    }

    public function effectiveCooldownSeconds(): int
    {
        return $this->auto_reply_cooldown_seconds ?? (int) config('openwa.auto_reply.default_cooldown_seconds');
    }
}
