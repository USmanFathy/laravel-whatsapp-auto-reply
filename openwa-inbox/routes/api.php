<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public endpoint for OpenWA. Authenticated by the HMAC signature, not by session/CSRF.
Route::post('/openwa/webhook', WebhookController::class)->name('openwa.webhook');
