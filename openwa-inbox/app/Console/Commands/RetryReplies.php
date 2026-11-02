<?php

namespace App\Console\Commands;

use App\Jobs\SendAutoReply;
use App\Models\AutoReplyLog;
use Illuminate\Console\Command;

class RetryReplies extends Command
{
    protected $signature = 'openwa:retry-replies {--minutes=2 : Only logs untouched for this long} {--failed : Also re-queue failed replies}';

    protected $description = 'Re-queue automatic replies that were recorded but never sent (e.g. the queue worker was down)';

    public function handle(): int
    {
        $statuses = [AutoReplyLog::PENDING];
        if ($this->option('failed')) {
            $statuses[] = AutoReplyLog::FAILED;
        }

        $logs = AutoReplyLog::whereIn('status', $statuses)
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        foreach ($logs as $log) {
            if ($log->status === AutoReplyLog::FAILED) {
                $log->update(['status' => AutoReplyLog::PENDING]);
            }
            SendAutoReply::dispatch($log->id); // the job itself refuses to send twice
        }

        $this->info("Re-queued {$logs->count()} automatic repl".($logs->count() === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
