# OpenWA + Laravel Inbox

Two independent applications, native Windows, no Docker:

| Folder | What it is |
|---|---|
| `OpenWA/` | Unmodified copy of https://github.com/rmyndharis/OpenWA (MIT, v0.25.0 snapshot) - the WhatsApp gateway (Node.js) |
| `openwa-inbox/` | Laravel 12 app (genuine `laravel/laravel` skeleton v12.12.2 + this project's code), SQLite, Blade UI |

Start with **`WINDOWS-SETUP.md`**.

## Features

Synchronise existing chats and stored messages; import live WhatsApp Web history where the engine supports it; keep
incoming/outgoing messages in sync through signed webhooks; inbox UI; per-conversation automatic-reply switch, text and
cooldown edited in a form; duplicate-safe, retry-safe replies. Details: `openwa-inbox/README.md`.

## What has / has not been verified

Be aware that this was produced in a sandbox without access to Packagist or a WhatsApp account.

| Item | Status |
|---|---|
| All PHP files pass `php -l` (PHP 8.3) | **Verified** |
| `composer.json` passes `composer validate` | **Verified** |
| PHP signature check equals OpenWA's own compiled `generateSignature()` on the same body (UTF-8 content) | **Verified** |
| OpenWA source builds with `nest build` on Node 22 | **Verified** (`npm ci --ignore-scripts`) |
| Endpoint paths, auth header, webhook headers/payload fields used by the Laravel app | Checked against OpenWA's `openapi.json`, docs and source |
| `composer install` / `php artisan test` (the automated suite: ~60 tests) | **NOT RUN** - Packagist is unreachable from the sandbox, so Laravel could not be installed. The tests have never been executed; expect to possibly fix small errors. |
| Migrations, Blade views, controllers executing under Laravel | **NOT RUN** |
| OpenWA starting, `npm install` of its native modules/Chromium | **NOT RUN** (native `better-sqlite3` could not be built/downloaded in the sandbox) |
| Real WhatsApp session: QR login, real webhooks, real sync, real replies | **NOT TESTED** |
| Every PowerShell command in `WINDOWS-SETUP.md`, winget package ids | **NOT RUN** on Windows |

Known functional limits: OpenWA only stores messages it saw while running; live history exists only for the
`whatsapp-web.js` engine; the ack/edit/revoke handlers follow the documented payloads but were never fed real events.
