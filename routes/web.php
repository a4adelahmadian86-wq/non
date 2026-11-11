<?php

use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/web_core.php';
require __DIR__.'/store_routes.php';
require __DIR__.'/support_extra.php';
require __DIR__.'/notifications.php';
require __DIR__.'/messenger.php';
require __DIR__.'/product_operations.php';

// Liveness is provided by Laravel at /up; readiness additionally requires the primary database.
// Bypass session startup and cache-backed throttling so this endpoint can report a DB outage
// instead of failing in middleware before the readiness check executes.
Route::get('/ready', ReadinessController::class)
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('health.ready');
