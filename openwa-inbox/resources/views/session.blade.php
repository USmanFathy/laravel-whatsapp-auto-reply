@extends('layouts.app')
@section('title', 'OpenWA session')
@section('body')
<header><h1>OpenWA session</h1><a href="{{ route('inbox') }}">← Inbox</a></header>
<main class="page">
    @if (session('status'))<div class="flash" style="margin:0 0 12px">{{ session('status') }}</div>@endif
    @if ($errors->has('session'))<div class="errors" style="margin:0 0 12px">{{ $errors->first('session') }}</div>@endif
    @if ($error)
        <div class="errors" style="margin:0 0 12px">{{ $error }}</div>
        <p>Check <code>OPENWA_BASE_URL</code>, <code>OPENWA_API_KEY</code> and <code>OPENWA_SESSION_ID</code> in <code>.env</code> and that OpenWA is running.</p>
    @else
        <p>Status: <strong>{{ $session['status'] ?? 'unknown' }}</strong>
            @if (! empty($session['phone'])) — phone {{ $session['phone'] }} @endif</p>
        @if ($qr)
            <p>Scan with WhatsApp → Linked devices → Link a device:</p>
            <img src="{{ $qr }}" alt="WhatsApp QR code" style="width:280px;height:280px;background:#fff;padding:8px">
            <p class="meta">The QR code rotates; reload this page if scanning fails.</p>
        @endif
        <form method="POST" action="{{ route('session.start') }}">@csrf<button type="submit">Start session</button></form>
        <p class="meta">Reload this page to refresh the status.</p>
    @endif
</main>
@endsection
