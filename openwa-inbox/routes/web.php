<?php

use App\Http\Controllers\AutoReplyController;
use App\Http\Middleware\RequireAdminPassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1', RequireAdminPassword::class])->group(function () {
    Route::redirect('/', '/auto-reply');
    Route::get('/auto-reply', [AutoReplyController::class, 'show'])->name('auto-reply');
    Route::put('/auto-reply', [AutoReplyController::class, 'update'])->name('auto-reply.update');
    Route::post('/auto-reply/retry-failed', [AutoReplyController::class, 'retryFailed'])->name('auto-reply.retry-failed');
});
