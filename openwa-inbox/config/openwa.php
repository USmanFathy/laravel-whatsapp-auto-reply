<?php

return [
    'base_url' => rtrim((string) env('OPENWA_BASE_URL', 'http://127.0.0.1:2785'), '/'),
    'api_key' => env('OPENWA_API_KEY'),
    'session_id' => env('OPENWA_SESSION_ID'),
    'webhook_secret' => env('OPENWA_WEBHOOK_SECRET'),
    'webhook_url' => env('OPENWA_WEBHOOK_URL', 'http://127.0.0.1:8000/api/openwa/webhook'),

    'timeout' => (int) env('OPENWA_TIMEOUT', 15),
    // Extra attempts made INSIDE one HTTP call (used for webhook registration only).
    // Reply sending uses 0 here: the queue job retries with backoff, so a worker is never held up.
    'http_retries' => (int) env('OPENWA_HTTP_RETRIES', 2),
    'http_retry_delay_ms' => (int) env('OPENWA_HTTP_RETRY_DELAY_MS', 300),

    'auto_reply' => [
        // Loop/spam safety valve, NOT a cooldown: at most this many replies to the SAME number per rolling minute.
        'max_per_sender_per_minute' => (int) env('AUTO_REPLY_MAX_PER_SENDER_PER_MINUTE', 6),
        // Messages older than this are not answered (e.g. a long outage); the reason is logged.
        'max_message_age_seconds' => (int) env('AUTO_REPLY_MAX_MESSAGE_AGE_SECONDS', 3600),
        'reply_in_groups' => (bool) env('AUTO_REPLY_IN_GROUPS', false),
        'job_tries' => 5,
        'job_timeout' => 45,
        'max_length' => 4096,
    ],

    // Credentials for the /auto-reply page (HTTP Basic). The page is disabled until a password is set.
    'admin' => [
        'user' => (string) env('AUTO_REPLY_ADMIN_USER', 'admin'),
        'password' => (string) env('AUTO_REPLY_ADMIN_PASSWORD', ''),
    ],

    // Only incoming messages matter. Subscribing to nothing else keeps webhook traffic (and OpenWA retries) minimal.
    'webhook_events' => ['message.received'],
];
