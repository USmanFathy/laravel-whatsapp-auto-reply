# Windows setup (native, no Docker)

> **Verification status:** these steps have **not** been executed on a Windows machine, and the Laravel app has
> not been booted with a real OpenWA/WhatsApp session. See "What has / has not been verified" in `README.md`.
> If a command fails, the error message is the most reliable guide; please report it.

Run everything in **PowerShell**. `$root` below is the folder you extract to.

## 1. Install required software

```powershell
winget install --id OpenJS.NodeJS.LTS -e        # OpenWA needs Node >= 22.19
winget install --id PHP.PHP.8.3 -e              # PHP 8.2+ is required by Laravel 12
winget install --id Composer.Composer -e
```

Close PowerShell and open a **new** window so the new PATH is used, then check:

```powershell
node -v      # must be v22.19 or newer
php -v       # 8.2 or newer
composer -V
```

If a winget id is not found, install manually: Node.js LTS from https://nodejs.org, PHP (Thread Safe x64 zip) from
https://windows.php.net/download (unzip to `C:\php` and add it to PATH), Composer from https://getcomposer.org/download.

Enable the PHP extensions Laravel/SQLite need (creates `php.ini` if there is none):

```powershell
$phpDir = Split-Path (Get-Command php).Source
if (-not (Test-Path "$phpDir\php.ini")) { Copy-Item "$phpDir\php.ini-development" "$phpDir\php.ini" }
(Get-Content "$phpDir\php.ini") `
  -replace '^;\s*extension_dir\s*=\s*"ext"', 'extension_dir = "ext"' `
  -replace '^;\s*extension=(curl|fileinfo|mbstring|openssl|pdo_sqlite|sqlite3|zip)\s*$', 'extension=$1' |
  Set-Content "$phpDir\php.ini"
php -m | Select-String -Pattern '^(curl|fileinfo|mbstring|openssl|PDO|pdo_sqlite|sqlite3)$'
```

You should see `curl, fileinfo, mbstring, openssl, pdo_sqlite, sqlite3` in the list.

## 2. Extract and open the project

```powershell
Expand-Archive -Path "$HOME\Downloads\OpenWA-Laravel-Inbox.zip" -DestinationPath "$HOME\Projects" -Force
$root = "$HOME\Projects\OpenWA-Laravel-Inbox"
cd $root
dir                    # OpenWA, openwa-inbox, README.md, WINDOWS-SETUP.md
```

## 3. Install OpenWA dependencies

```powershell
cd "$root\OpenWA"
npm install            # downloads Chromium for WhatsApp Web (~150-300 MB); can take several minutes
npm run build
Copy-Item .env.minimal .env
```

Allow the local Laravel app as a webhook target (OpenWA blocks loopback webhook URLs by default):

```powershell
Add-Content .env "`nSSRF_ALLOWED_HOSTS=127.0.0.1,localhost"
```

## 4. Install Laravel dependencies

```powershell
cd "$root\openwa-inbox"
composer install
```

## 5. Configure environment variables

```powershell
cd "$root\openwa-inbox"
Copy-Item .env.example .env
php artisan key:generate

# random webhook secret (>= 16 chars); copy the printed value into OPENWA_WEBHOOK_SECRET
$b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
($b | ForEach-Object { $_.ToString('x2') }) -join ''

notepad .env
```

In `.env` set `OPENWA_WEBHOOK_SECRET=` to that value. Leave `OPENWA_API_KEY` and `OPENWA_SESSION_ID` empty for now;
you obtain them in steps 7-8. Close Notepad after saving.

## 6. Create and migrate the SQLite database

```powershell
cd "$root\openwa-inbox"
New-Item -ItemType File -Path database\database.sqlite -Force | Out-Null
php artisan migrate --force
```

(This also creates the `jobs` table used by the queue.)

## 7. Start OpenWA  (keep this window open)

```powershell
cd "$root\OpenWA"
npm run start:prod
```

On first start OpenWA creates an ADMIN API key. It is printed in the startup banner and saved to
`$root\OpenWA\data\.api-key`. Open a **second** PowerShell window:

```powershell
$root = "$HOME\Projects\OpenWA-Laravel-Inbox"
notepad "$root\OpenWA\data\.api-key"          # copy the key
```

Create the session and note its id:

```powershell
$key = "PASTE_THE_KEY_HERE"
$session = Invoke-RestMethod -Method Post -Uri http://127.0.0.1:2785/api/sessions `
  -Headers @{ 'X-API-Key' = $key } -ContentType 'application/json' -Body '{"name":"inbox"}'
$session.id
```

Put both values into `$root\openwa-inbox\.env`:

```powershell
notepad "$root\openwa-inbox\.env"             # OPENWA_API_KEY=<key>   OPENWA_SESSION_ID=<session id>
```

## 8. Connect WhatsApp with the QR code  (needs a real WhatsApp account/phone)

Start Laravel first (step 9) and use the built-in page, or call the API directly:

```powershell
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:2785/api/sessions/$($session.id)/start" -Headers @{ 'X-API-Key' = $key }
```

Open http://127.0.0.1:8000/session in the browser (after step 9), reload until the status is `qr_ready`, and scan the
QR code with WhatsApp on your phone: **Settings > Linked devices > Link a device**. The status becomes `ready`.

## 9. Start Laravel  (two more windows)

Window A - web server:

```powershell
cd "$HOME\Projects\OpenWA-Laravel-Inbox\openwa-inbox"
php artisan serve --host=127.0.0.1 --port=8000
```

Window B - queue worker (sends automatic replies, retries failures):

```powershell
cd "$HOME\Projects\OpenWA-Laravel-Inbox\openwa-inbox"
php artisan queue:work --tries=5
```

> Without the queue worker, messages are still recorded but automatic replies stay `pending`.

## 10. Register the webhook

```powershell
cd "$HOME\Projects\OpenWA-Laravel-Inbox\openwa-inbox"
php artisan config:clear
php artisan openwa:register-webhook
```

This calls `POST /api/sessions/{id}/webhooks` with your URL, the secret and the events
`message.received, message.sent, message.ack, message.edited, message.revoked`. Running it again updates the
existing webhook. If registration is refused with an SSRF error, check `SSRF_ALLOWED_HOSTS` in `OpenWA\.env`
and restart OpenWA.

## 11. Open the inbox

```powershell
Start-Process http://127.0.0.1:8000
```

The inbox has no login. It is meant for local use only; do not expose port 8000 to a network.

## 12. Test conversation synchronization  (needs the `ready` WhatsApp session)

```powershell
php artisan openwa:sync              # chats + messages that OpenWA has stored
php artisan openwa:sync --history    # additionally asks WhatsApp Web for older messages (see limits)
```

Or click **Sync now** in the inbox. Then, from another phone, send a message to your linked number: it appears
in the inbox within seconds via the webhook without syncing. Send a message from the linked phone: it appears as an
outgoing message (`message.sent`).

Limits you should expect:
- OpenWA's `/messages` only returns messages OpenWA itself stored, i.e. those that arrived while the session was running.
- `--history` (live WhatsApp Web history) works only on the `whatsapp-web.js` engine, which is what `.env.minimal` selects.
  On other engines OpenWA answers HTTP 501 and the sync reports "not supported".

## 13. Test automatic replies

1. Open a conversation in the inbox.
2. Under **Automatic reply**, tick the checkbox, write a text, press **Save**.
3. From the other phone send a message to the linked number. The reply appears in the thread (status `sent` in
   "Recent automatic replies").
4. Another message within the cooldown (default 300 s, configurable per conversation) is recorded but not answered
   (`skipped: cooldown`). Set the cooldown to `0` to answer every message.

## 14. Enable/disable automatic replies per conversation

Each conversation has its own checkbox, text and cooldown; the list shows an `auto` badge for enabled ones.
Enable conversation A and B, then untick and save A: new messages in B are still answered, A is only recorded.
Messages are always recorded whatever the setting.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Webhook returns 401 | `OPENWA_WEBHOOK_SECRET` differs from the one registered; run `php artisan config:clear` then `php artisan openwa:register-webhook` again. |
| Replies stay `pending` | The queue worker (window B) is not running. |
| Reply `failed` | See the error in "Recent automatic replies"; press **Retry** after fixing the cause. |
| `could not find driver` | `pdo_sqlite` is not enabled in `php.ini` (step 1). |
| `npm install` fails building a native module | Install Visual Studio Build Tools ("Desktop development with C++") and retry. |

## Run the automated tests (no WhatsApp needed)

```powershell
cd "$HOME\Projects\OpenWA-Laravel-Inbox\openwa-inbox"
composer install
php artisan test
```
