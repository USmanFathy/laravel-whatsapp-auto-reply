@extends('layouts.app')
@section('title', 'Automatic reply')
@section('body')
<h1>WhatsApp automatic reply
    <span class="state {{ $setting->enabled ? 'on' : 'off' }}">{{ $setting->enabled ? 'ON' : 'OFF' }}</span>
</h1>

@if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
@if ($errors->any())
    <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif
@if ($queueDriver === 'sync')
    <div class="warn"><strong>QUEUE_CONNECTION=sync.</strong> Replies are sent inside the webhook request, which can stall OpenWA's deliveries. Set <code>QUEUE_CONNECTION=database</code> in <code>.env</code> and run the queue worker.</div>
@endif

<form class="card" method="POST" action="{{ route('auto-reply.update') }}">
    @csrf
    @method('PUT')
    <label class="switch">
        <input type="checkbox" name="enabled" value="1" {{ old('enabled', $setting->enabled) ? 'checked' : '' }}>
        Reply automatically to every incoming number
    </label>

    <h2><label for="message">Reply message (same text for everyone)</label></h2>
    <textarea id="message" name="message" maxlength="{{ config('openwa.auto_reply.max_length') }}" placeholder="Thanks for your message! We will get back to you soon.">{{ old('message', $setting->message) }}</textarea>
    <p class="muted">Applies to new incoming messages immediately. Replies are plain text, sent through OpenWA. Group chats, status updates and your own messages are never answered.</p>
    <button type="submit">Save</button>
</form>

<section class="card">
    <h2>Health</h2>
    <p class="muted" style="margin-top:0">
        Queue driver: <strong>{{ $queueDriver }}</strong>
        @if ($queuedJobs !== null) · jobs waiting: <strong>{{ $queuedJobs }}</strong>@endif
        · pending replies: <strong>{{ $pendingCount }}</strong>
        · failed replies: <strong>{{ $failedCount }}</strong><br>
        Last webhook from OpenWA:
        @if ($lastWebhook) <strong>{{ $lastWebhook->created_at->diffForHumans() }}</strong> ({{ $lastWebhook->event }} → {{ $lastWebhook->outcome }})
        @else <strong>none received yet</strong> (register the webhook, see WINDOWS-SETUP.md) @endif
    </p>
    @if ($pendingCount > 0 && $queuedJobs === 0)
        <div class="warn">Replies are pending but no jobs are queued: run <code>php artisan openwa:retry-replies --minutes=0</code>.</div>
    @endif
    @if ($failedCount > 0)
        <form method="POST" action="{{ route('auto-reply.retry-failed') }}">@csrf<button class="secondary" type="submit">Retry {{ $failedCount }} failed repl{{ $failedCount === 1 ? 'y' : 'ies' }}</button></form>
    @endif
</section>

@if ($recent->isNotEmpty())
<section class="card">
    <h2>Last 10 reply attempts</h2>
    <div class="scroll">
        <table>
            <tr><th>When</th><th>Number</th><th>Status</th><th>Tries</th><th>Detail</th></tr>
            @foreach ($recent as $log)
                <tr>
                    <td>{{ $log->created_at->diffForHumans() }}</td>
                    <td>{{ preg_replace('/@.*$/', '', $log->chat_id) }}</td>
                    <td>{{ $log->status }}</td>
                    <td>{{ $log->attempts }}</td>
                    <td class="wrap">{{ $log->skip_reason ?: $log->last_error }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</section>
@endif
@endsection
