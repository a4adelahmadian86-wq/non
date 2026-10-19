<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Override / extend platform notification endpoints with full controller
    Route::get('/workspace/notifications', [NotificationController::class, 'index'])->name('platform.notifications');
    Route::post('/workspace/notifications/read-all', [NotificationController::class, 'readAll'])->name('platform.notifications.readAll');
    Route::post('/workspace/notifications/{id}/read', [NotificationController::class, 'read'])->name('platform.notifications.read');
    Route::get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
    Route::get('/api/notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
});
