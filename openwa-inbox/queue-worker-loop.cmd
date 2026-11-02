@echo off
rem Keeps the Laravel queue worker alive: restarts it if it crashes, after "php artisan queue:restart",
rem and every hour (--max-time) so it never runs on stale configuration.
cd /d "%~dp0"
:loop
php artisan queue:work --sleep=1 --tries=5 --timeout=60 --max-time=3600
echo Worker stopped, restarting in 3 seconds...
timeout /t 3 /nobreak >nul
goto loop
