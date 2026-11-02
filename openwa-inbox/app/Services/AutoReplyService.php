<?php

namespace App\Services;

use App\Models\AutoReplyLog;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class AutoReplyService
{
    /**
     * Decides whether a newly stored LIVE incoming message gets an automatic reply and records
     * the decision. Settings are read from this conversation only, so other conversations are
     * never affected. Returns the pending log (to be dispatched) or null.
     *
     * Safe to call repeatedly: trigger_message_id is unique, so a message can never be answered twice.
     */
    public function evaluate(Message $message, Conversation $conversation): ?AutoReplyLog
    {
        if (! $message->isIncoming() || $message->type === 'revoked') {
            return null;
        }
        if (AutoReplyLog::where('trigger_message_id', $message->id)->exists()) {
            return null;
        }

        // Re-read the settings so a form change that just happened is honoured.
        $conversation = Conversation::findOrFail($conversation->id);
        if (! $conversation->auto_reply_enabled) {
            return null;
        }

        $text = trim((string) $conversation->auto_reply_text);
        if ($text === '') {
            return $this->skip($message, $conversation, 'empty_reply_text');
        }

        $maxAge = (int) config('openwa.auto_reply.max_message_age_seconds');
        if ($message->sent_at && $message->sent_at->lt(now()->subSeconds($maxAge))) {
            return $this->skip($message, $conversation, 'message_too_old');
        }

        // Atomic cooldown claim: only one concurrent evaluator can move last_auto_reply_at.
        $cooldown = $conversation->effectiveCooldownSeconds();
        $claimed = Conversation::whereKey($conversation->id)
            ->where('auto_reply_enabled', true)
            ->where(function ($q) use ($cooldown) {
                $q->whereNull('last_auto_reply_at')->orWhere('last_auto_reply_at', '<=', now()->subSeconds($cooldown));
            })
            ->update(['last_auto_reply_at' => now()]);

        if ($claimed === 0) {
            return $this->skip($message, $conversation, 'cooldown');
        }

        return $this->create($message, $conversation, $text, AutoReplyLog::PENDING, null);
    }

    private function skip(Message $message, Conversation $conversation, string $reason): AutoReplyLog
    {
        return $this->create($message, $conversation, trim((string) $conversation->auto_reply_text), AutoReplyLog::SKIPPED, $reason);
    }

    private function create(Message $message, Conversation $conversation, string $text, string $status, ?string $reason): ?AutoReplyLog
    {
        return DB::transaction(fn () => AutoReplyLog::firstOrCreate(
            ['trigger_message_id' => $message->id],
            ['conversation_id' => $conversation->id, 'reply_text' => $text, 'status' => $status, 'skip_reason' => $reason],
        ));
    }
}
