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
// Liveness is provided by Laravel at /up; readiness additionally requires the primary database.
// This server-to-server probe must not depend on browser cookies, sessions, auth,
// CSRF, or admin-scope middleware; otherwise a database outage can mask its 503.
Route::get('/ready', ReadinessController::class)
    ->withoutMiddleware([
        \\Illuminate\\Cookie\\Middleware\\EncryptCookies::class,
        \\Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse::class,
        \\Illuminate\\Session\\Middleware\\StartSession::class,
        \\Illuminate\\View\\Middleware\\ShareErrorsFromSession::class,
        \\Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken::class,
        \\App\\Http\\Middleware\\EnsureActiveAccount::class,
        \\App\\Http\\Middleware\\ResolveAdminScope::class,
        \\App\\Http\\Middleware\\TrackAuthenticatedSession::class,
        \\App\\Http\\Middleware\\RequireTwoFactorChallenge::class,
    ])
    ->name('health.ready');
