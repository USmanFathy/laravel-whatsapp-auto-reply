<?php

namespace App\Jobs;

use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the global reply for ONE incoming message. Jobs are independent of each other: there is no shared
 * lock, cooldown or counter, so a slow, failing or backed-off job never delays the replies to other messages.
 */
class SendAutoReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public int $logId)
    {
        $this->tries = (int) config('openwa.auto_reply.job_tries', 5);
        $this->timeout = (int) config('openwa.auto_reply.job_timeout', 45);
    }

    /** @return list<int> seconds before each retry (released back to the queue, the worker moves on meanwhile) */
    public function backoff(): array
    {
        return [5, 15, 45, 120];
    }

    public function handle(OpenWaClient $client): void
    {
        $log = AutoReplyLog::find($this->logId);
        if (! $log || in_array($log->status, [AutoReplyLog::SENT, AutoReplyLog::SKIPPED], true)) {
            return; // already handled: never send twice
        }

        $setting = AutoReplySetting::current();
        if (! $setting->enabled || $setting->replyText() === '') {
            $this->skip($log, $setting->enabled ? 'no_reply_text' : 'disabled_before_send');

            return;
        }

        $text = $setting->replyText();
        $log->increment('attempts');

        try {
            // retries: 0 - the queue does the retrying (with backoff) so this worker is free again after one HTTP timeout.
            // The Idempotency-Key is identical on every attempt, so a retry after a lost response cannot double-send.
            $response = $client->sendText($log->chat_id, $text, 'autoreply-'.sha1($log->wa_message_id), 0);
        } catch (OpenWaException $e) {
            $log->update(['last_error' => $e->getMessage()]);
            if (! $e->retryable) {
                $log->update(['status' => AutoReplyLog::FAILED]);
                Log::warning('openwa.auto_reply failed permanently', ['log_id' => $log->id, 'chat' => $log->chat_id, 'error' => $e->getMessage()]);

                return;
            }
            Log::notice('openwa.auto_reply will retry', ['log_id' => $log->id, 'attempt' => $log->attempts, 'error' => $e->getMessage()]);
            throw $e;
        }

        $log->update([
            'status' => AutoReplyLog::SENT,
            'reply_text' => $text,
            'sent_at' => now(),
            'openwa_message_id' => $response['messageId'] ?? null,
            'last_error' => null,
        ]);
        Log::info('openwa.auto_reply sent', ['log_id' => $log->id, 'chat' => $log->chat_id]);
    }

    /** Called by the queue after the last allowed attempt. */
    public function failed(Throwable $e): void
    {
        AutoReplyLog::whereKey($this->logId)
            ->whereNotIn('status', [AutoReplyLog::SENT, AutoReplyLog::SKIPPED])
            ->update(['status' => AutoReplyLog::FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
        Log::error('openwa.auto_reply gave up', ['log_id' => $this->logId, 'error' => $e->getMessage()]);
    }

    private function skip(AutoReplyLog $log, string $reason): void
    {
        $log->update(['status' => AutoReplyLog::SKIPPED, 'skip_reason' => $reason]);
        Log::notice('openwa.auto_reply skipped', ['log_id' => $log->id, 'reason' => $reason]);
    }
}
