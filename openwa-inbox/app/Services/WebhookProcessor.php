<?php

namespace App\Services;

use App\Jobs\SendAutoReply;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles an authenticated OpenWA webhook. It does ONLY database work and queues the reply:
 * it never calls OpenWA, so the HTTP response goes back to OpenWA within milliseconds
 * (OpenWA's delivery timeout is 10 s and it retries / throttles a session whose receiver is slow).
 *
 * Every decision is written to webhook_events.outcome and to the Laravel log with its reason.
 */
class WebhookProcessor
{
    /** Message types that are not customer messages. */
    private const IGNORED_TYPES = ['revoked', 'call', 'masked', 'unknown'];

    /** Returns the outcome string (also stored in webhook_events.outcome). */
    public function process(array $payload, string $idempotencyKey, ?string $deliveryId = null): string
    {
        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (WebhookEvent::where('idempotency_key', $idempotencyKey)->exists()) {
            $this->report('duplicate', $event, $data, 'same idempotency key already processed');

            return 'duplicate';
        }

        $log = null;
        try {
            $outcome = DB::transaction(function () use ($event, $data, $idempotencyKey, $deliveryId, &$log) {
                $row = WebhookEvent::create([
                    'idempotency_key' => $idempotencyKey,
                    'delivery_id' => $deliveryId,
                    'event' => $event,
                ]);

                [$outcome, $log] = $this->decide($event, $data);
                $row->update(['outcome' => $outcome]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event / message won the race.
            $this->report('duplicate', $event, $data, 'concurrent duplicate');

            return 'duplicate';
        }

        $this->report($outcome, $event, $data);

        // Queued after the commit. If queueing fails the pending row survives and
        // `php artisan openwa:retry-replies` (or the worker's next sweep) picks it up.
        if ($log instanceof AutoReplyLog) {
            try {
                SendAutoReply::dispatch($log->id);
            } catch (\Throwable $e) {
                Log::error('openwa.auto_reply: could not queue reply', ['log_id' => $log->id, 'error' => $e->getMessage()]);
            }
        }

        return $outcome;
    }

    /** @return array{0:string,1:?AutoReplyLog} */
    private function decide(string $event, array $data): array
    {
        if ($event !== 'message.received') {
            return ['ignored:event', null];
        }

        $waId = $this->str($data['id'] ?? null);
        $chatId = $this->str($data['chatId'] ?? null) ?? $this->str($data['from'] ?? null);
        if ($waId === null || $chatId === null) {
            return ['ignored:malformed', null];
        }

        // Our own messages (including the automatic reply itself) must never trigger a reply.
        if (($data['fromMe'] ?? false) === true) {
            return ['ignored:outgoing', null];
        }

        $kind = $this->str($data['kind'] ?? null);
        if (($data['isStatusBroadcast'] ?? false) === true || $chatId === 'status@broadcast' || in_array($kind, ['status', 'broadcast'], true)) {
            return ['ignored:broadcast', null];
        }
        if ($kind === 'channel' || str_ends_with($chatId, '@newsletter')) {
            return ['ignored:channel', null];
        }
        $isGroup = ($data['isGroup'] ?? false) === true || $kind === 'group' || str_ends_with($chatId, '@g.us');
        if ($isGroup && ! config('openwa.auto_reply.reply_in_groups')) {
            return ['ignored:group', null];
        }
        if (in_array($this->str($data['type'] ?? null), self::IGNORED_TYPES, true)) {
            return ['ignored:type', null];
        }

        $setting = AutoReplySetting::current();
        if (! $setting->enabled) {
            return ['skipped:disabled', null];
        }
        if ($setting->replyText() === '') {
            return ['skipped:no_reply_text', null];
        }

        $maxAge = (int) config('openwa.auto_reply.max_message_age_seconds');
        $timestamp = is_numeric($data['timestamp'] ?? null) ? (int) $data['timestamp'] : null;
        if ($timestamp !== null && $maxAge > 0 && $timestamp < now()->subSeconds($maxAge)->getTimestamp()) {
            return ['skipped:too_old', null];
        }

        if (AutoReplyLog::where('wa_message_id', $waId)->exists()) {
            return ['duplicate_message', null];
        }

        // Loop guard (two auto-responders talking to each other): a short rolling window per number,
        // counting only replies that were actually sent or are still on their way. Failed replies do not count.
        $max = (int) config('openwa.auto_reply.max_per_sender_per_minute');
        if ($max > 0) {
            $recent = AutoReplyLog::where('chat_id', $chatId)
                ->whereIn('status', [AutoReplyLog::PENDING, AutoReplyLog::SENT])
                ->where('created_at', '>=', now()->subMinute())
                ->count();
            if ($recent >= $max) {
                return ['skipped:sender_rate_limited', null];
            }
        }

        $log = AutoReplyLog::create([
            'wa_message_id' => $waId,
            'chat_id' => $chatId,
            'status' => AutoReplyLog::PENDING,
        ]);

        return ['queued', $log];
    }

    private function report(string $outcome, string $event, array $data, ?string $detail = null): void
    {
        $context = [
            'event' => $event,
            'outcome' => $outcome,
            'wa_message_id' => $data['id'] ?? null,
            'chat' => $data['chatId'] ?? $data['from'] ?? null,
            'detail' => $detail,
        ];

        $level = (str_starts_with($outcome, 'ignored') || str_starts_with($outcome, 'skipped') || $outcome === 'duplicate' || $outcome === 'duplicate_message')
            ? 'notice' : 'info';
        Log::log($level, 'openwa.webhook '.$outcome, $context);
    }

    private function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
