<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/workspace/notifications', [NotificationController::class, 'index'])->name('platform.notifications');
    Route::get('/workspace/notifications/{id}/visit', [NotificationController::class, 'visit'])->whereNumber('id')->name('platform.notifications.visit');
    Route::post('/workspace/notifications/read-all', [NotificationController::class, 'readAll'])->name('platform.notifications.readAll');
    Route::post('/workspace/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id')->name('platform.notifications.read');
    Route::post('/workspace/notifications/{id}/archive', [NotificationController::class, 'archive'])->whereNumber('id')->name('platform.notifications.archive');
    Route::post('/workspace/notifications/{id}/restore', [NotificationController::class, 'restore'])->whereNumber('id')->name('platform.notifications.restore');
    Route::get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
    Route::get('/api/notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
});
