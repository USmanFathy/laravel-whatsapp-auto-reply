<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\UpdateAutoReplyRequest;
use App\Jobs\SendAutoReply;
use App\Models\AutoReplyLog;
use App\Models\Conversation;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaException;
use App\Services\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request): View
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        return $this->render($request, $conversation);
    }

    public function updateAutoReply(UpdateAutoReplyRequest $request, Conversation $conversation): RedirectResponse
    {
        $data = $request->validated();
        $conversation->update([
            'auto_reply_enabled' => $data['auto_reply_enabled'],
            'auto_reply_text' => $data['auto_reply_text'] ?? null,
            'auto_reply_cooldown_seconds' => $data['auto_reply_cooldown_seconds'] ?? null,
        ]);

        return redirect()->route('conversations.show', $conversation)
            ->with('status', 'Automatic reply settings saved for this conversation only.');
    }

    public function send(SendMessageRequest $request, Conversation $conversation, OpenWaClient $client): RedirectResponse
    {
        try {
            $client->sendText($conversation->chat_id, $request->validated('text'), 'manual-'.Str::uuid());
        } catch (OpenWaException $e) {
            return back()->withInput()->withErrors(['text' => $e->getMessage()]);
        }

        // The stored copy arrives through the message.sent webhook (or the next sync).
        return back()->with('status', 'Message sent through OpenWA.');
    }

    public function sync(Request $request, SyncService $sync): RedirectResponse
    {
        set_time_limit(300);
        try {
            $r = $sync->run(withHistory: $request->boolean('history'));
        } catch (OpenWaException $e) {
            return back()->withErrors(['sync' => 'Sync failed: '.$e->getMessage()]);
        }

        $msg = "Synced {$r['chats']} chats, {$r['messages_new']} new messages ({$r['messages_seen']} seen).";
        if ($r['history_supported'] === false) {
            $msg .= ' Live history is not supported by this OpenWA engine (HTTP 501).';
        }
        $redirect = back()->with('status', $msg);

        return $r['errors'] ? $redirect->withErrors(['sync' => implode(' | ', array_slice($r['errors'], 0, 5))]) : $redirect;
    }

    public function retryReply(AutoReplyLog $log): RedirectResponse
    {
        if ($log->status === AutoReplyLog::FAILED) {
            $log->update(['status' => AutoReplyLog::PENDING, 'last_error' => null]);
            SendAutoReply::dispatch($log->id);
        }

        return back()->with('status', 'Automatic reply re-queued.');
    }

    private function render(Request $request, ?Conversation $selected): View
    {
        $search = trim((string) $request->query('q', ''));
        $conversations = Conversation::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('chat_id', 'like', "%{$search}%")))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(200)->get();

        $messages = collect();
        $logs = collect();
        if ($selected) {
            $messages = $selected->messages()->orderByDesc('sent_at')->orderByDesc('id')->limit(300)->get()->reverse()->values();
            $logs = $selected->autoReplyLogs()->latest('id')->limit(10)->get();
        }

        return view('inbox', compact('conversations', 'selected', 'messages', 'logs', 'search'));
    }
}
