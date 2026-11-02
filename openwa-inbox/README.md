# OpenWA auto-reply (Laravel 12)

Small Laravel app next to an unmodified [OpenWA](https://github.com/rmyndharis/OpenWA) gateway. It has **no inbox**:
use the OpenWA dashboard for chats. Laravel only

1. receives OpenWA's signed `message.received` webhook (`POST /api/openwa/webhook`),
2. sends one global, configurable reply to every incoming individual chat through `POST /api/sessions/{id}/messages/send-text`,
3. offers one protected page, **`/auto-reply`**, to edit that message and switch replies on/off.

## Routes
| Route | Purpose |
|---|---|
| `GET/PUT /auto-reply` | settings page (HTTP Basic: `AUTO_REPLY_ADMIN_USER` / `AUTO_REPLY_ADMIN_PASSWORD`; 503 until a password is set) |
| `POST /auto-reply/retry-failed` | re-queue failed replies (same protection) |
| `POST /api/openwa/webhook` | OpenWA webhook; HMAC-SHA256 signature required |

## How a message is handled
`WebhookController` verifies `X-OpenWA-Signature` over the raw body -> `WebhookProcessor` (database work only, never calls
OpenWA, answers 200 for every outcome) -> `SendAutoReply` queue job -> OpenWA.

Every decision is saved in `webhook_events.outcome` and logged (`storage/logs/laravel.log`, lines `openwa.webhook ...` /
`openwa.auto_reply ...`): `queued`, `duplicate`, `duplicate_message`, `ignored:outgoing`, `ignored:event`, `ignored:group`,
`ignored:broadcast`, `ignored:channel`, `ignored:type`, `ignored:malformed`, `skipped:disabled`, `skipped:no_reply_text`,
`skipped:too_old`, `skipped:sender_rate_limited`; rejected requests log the reason (bad signature, missing secret, bad body).

| Concern | Mechanism |
|---|---|
| Duplicate delivery | `webhook_events.idempotency_key` unique + `auto_reply_logs.wa_message_id` unique |
| Own messages / reply loops | `fromMe` messages and all non-`message.received` events are ignored; max 6 replies per number per rolling minute (loop guard, not a cooldown) |
| One failure blocking others | no shared lock/cooldown; each reply is its own job; retries are released with backoff 5/15/45/120 s so the worker keeps going |
| Transient OpenWA errors | 408/409/425/429/5xx and connection errors are retried by the queue; 4xx are failed at once; `Idempotency-Key` is identical on every attempt |
| Fast webhook response | the reply is queued, never sent inside the request (requires `QUEUE_CONNECTION=database` and a running worker) |

Commands: `php artisan openwa:register-webhook`, `php artisan openwa:retry-replies [--failed] [--minutes=2]`, `php artisan test`.
Setup: `../WINDOWS-SETUP.md`.
