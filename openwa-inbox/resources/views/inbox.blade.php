@extends('layouts.app')
@section('title', 'Inbox')
@section('body')
<header>
    <h1>WhatsApp Inbox</h1>
    <a href="{{ route('inbox') }}">Conversations</a>
    <a href="{{ route('session') }}">Session / QR</a>
    <form method="POST" action="{{ route('sync') }}">
        @csrf
        <label class="inline" style="margin:0;font-weight:400"><input type="checkbox" name="history" value="1"> include live history</label>
        <button type="submit">Sync now</button>
    </form>
</header>
@if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
@if ($errors->has('sync'))<div class="errors">{{ $errors->first('sync') }}</div>@endif

<div class="layout">
    <aside class="list">
        <form class="search" method="GET" action="{{ route('inbox') }}">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search name or number">
        </form>
        @forelse ($conversations as $c)
            <a class="conv {{ $selected && $selected->id === $c->id ? 'active' : '' }}" href="{{ route('conversations.show', $c) }}">
                <div class="top">
                    <span>{{ $c->displayName() }}{{ $c->is_group ? ' (group)' : '' }}</span>
                    <span>
                        @if ($c->unread_count > 0)<span class="badge unread">{{ $c->unread_count }}</span>@endif
                        @if ($c->auto_reply_enabled)<span class="badge on">auto</span>@endif
                    </span>
                </div>
                <div class="preview">{{ $c->last_message_preview }}</div>
                <div class="meta">{{ $c->last_message_at?->diffForHumans() }}</div>
            </a>
        @empty
            <div class="empty">No conversations yet.<br>Click “Sync now” or wait for a webhook.</div>
        @endforelse
    </aside>

    <section class="detail">
        @if (! $selected)
            <div class="empty">Select a conversation.</div>
        @else
            <div class="panel" style="border-top:0;border-bottom:1px solid var(--line)">
                <strong>{{ $selected->displayName() }}</strong>
                <span class="meta">{{ $selected->chat_id }}</span>
            </div>
            <div class="thread">
                @forelse ($messages as $m)
                    <div class="msg {{ $m->direction }}">
                        {{ $m->type === 'revoked' ? '[message deleted]' : ($m->body !== '' && $m->body !== null ? $m->body : '['.$m->type.']') }}
                        <div class="meta">
                            {{ $m->author ?: '' }} {{ $m->sent_at?->format('Y-m-d H:i') }}
                            {{ $m->edited ? '· edited' : '' }} {{ $m->status ? '· '.$m->status : '' }}
                        </div>
                    </div>
                @empty
                    <div class="empty">No messages stored for this conversation.</div>
                @endforelse
            </div>

            <div class="panel">
                <form method="POST" action="{{ route('conversations.send', $selected) }}">
                    @csrf
                    <textarea name="text" rows="2" placeholder="Type a message…">{{ old('text') }}</textarea>
                    @error('text')<div class="err">{{ $message }}</div>@enderror
                    <div style="margin-top:6px"><button type="submit">Send</button></div>
                </form>
            </div>

            <div class="panel">
                <h2>Automatic reply — this conversation only</h2>
                <form method="POST" action="{{ route('conversations.auto-reply', $selected) }}">
                    @csrf
                    @method('PUT')
                    <label class="inline">
                        <input type="checkbox" name="auto_reply_enabled" value="1" {{ old('auto_reply_enabled', $selected->auto_reply_enabled) ? 'checked' : '' }}>
                        Reply automatically to incoming messages here
                    </label>
                    @error('auto_reply_enabled')<div class="err">{{ $message }}</div>@enderror

                    <label for="auto_reply_text">Reply text</label>
                    <textarea id="auto_reply_text" name="auto_reply_text" rows="3" maxlength="{{ config('openwa.auto_reply.max_length') }}">{{ old('auto_reply_text', $selected->auto_reply_text) }}</textarea>
                    @error('auto_reply_text')<div class="err">{{ $message }}</div>@enderror

                    <label for="cooldown">Minimum seconds between automatic replies (blank = default {{ config('openwa.auto_reply.default_cooldown_seconds') }})</label>
                    <input id="cooldown" type="number" name="auto_reply_cooldown_seconds" min="0" max="86400" value="{{ old('auto_reply_cooldown_seconds', $selected->auto_reply_cooldown_seconds) }}">
                    @error('auto_reply_cooldown_seconds')<div class="err">{{ $message }}</div>@enderror

                    <div style="margin-top:8px"><button type="submit">Save</button></div>
                </form>

                @if ($logs->isNotEmpty())
                    <h2 style="margin-top:14px">Recent automatic replies</h2>
                    <table>
                        <tr><th>When</th><th>Status</th><th>Attempts</th><th>Detail</th><th></th></tr>
                        @foreach ($logs as $l)
                            <tr>
                                <td>{{ $l->created_at->diffForHumans() }}</td>
                                <td>{{ $l->status }}</td>
                                <td>{{ $l->attempts }}</td>
                                <td>{{ $l->skip_reason ?: $l->last_error }}</td>
                                <td>
                                    @if ($l->status === 'failed')
                                        <form method="POST" action="{{ route('auto-replies.retry', $l) }}">@csrf<button class="secondary" type="submit">Retry</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>
        @endif
    </section>
</div>
@endsection
