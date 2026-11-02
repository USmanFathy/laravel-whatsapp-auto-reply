<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAutoReplyRequest;
use App\Jobs\SendAutoReply;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\WebhookEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AutoReplyController extends Controller
{
    public function show(): View
    {
        return view('auto-reply', [
            'setting' => AutoReplySetting::current(),
            'recent' => AutoReplyLog::latest('id')->limit(10)->get(),
            'failedCount' => AutoReplyLog::where('status', AutoReplyLog::FAILED)->count(),
            'pendingCount' => AutoReplyLog::where('status', AutoReplyLog::PENDING)->count(),
            'queueDriver' => config('queue.default'),
            'queuedJobs' => config('queue.default') === 'database' ? DB::table('jobs')->count() : null,
            'lastWebhook' => WebhookEvent::latest('id')->first(),
        ]);
    }

    public function update(UpdateAutoReplyRequest $request): RedirectResponse
    {
        $data = $request->validated();
        AutoReplySetting::current()->update([
            'enabled' => $data['enabled'],
            'message' => isset($data['message']) ? trim($data['message']) : null,
        ]);

        return redirect()->route('auto-reply')->with('status', $data['enabled']
            ? 'Saved. Automatic replies are ON for every incoming number.'
            : 'Saved. Automatic replies are OFF.');
    }

    public function retryFailed(): RedirectResponse
    {
        $logs = AutoReplyLog::where('status', AutoReplyLog::FAILED)->get();
        foreach ($logs as $log) {
            $log->update(['status' => AutoReplyLog::PENDING, 'last_error' => null]);
            SendAutoReply::dispatch($log->id);
        }

        return redirect()->route('auto-reply')->with('status', "Re-queued {$logs->count()} failed repl".($logs->count() === 1 ? 'y' : 'ies').'.');
    }
}
