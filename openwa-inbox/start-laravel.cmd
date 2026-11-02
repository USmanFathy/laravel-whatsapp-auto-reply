@echo off
rem Starts the Laravel web server and the (self-restarting) queue worker in two windows. Run from the openwa-inbox folder.
cd /d "%~dp0"
php artisan config:clear
start "Laravel web (8000)" cmd /k php artisan serve --host=127.0.0.1 --port=8000
start "Laravel queue worker" cmd /k queue-worker-loop.cmd
echo Started. Open http://127.0.0.1:8000/auto-reply
