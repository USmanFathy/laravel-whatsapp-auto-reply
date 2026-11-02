<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class AutoReplyLog extends Model
{
    use Prunable;

    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'wa_message_id', 'chat_id', 'status', 'skip_reason', 'attempts', 'last_error',
        'reply_text', 'openwa_message_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(30))->whereIn('status', [self::SENT, self::SKIPPED]);
    }
}
