<?php

use App\Http\Controllers\MessengerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('messenger')->name('messenger.')->group(function () {
    Route::get('/', [MessengerController::class, 'index'])->name('index');
    Route::post('/conversations', [MessengerController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('/conversations/{conversation}', [MessengerController::class, 'show'])->name('show');
    Route::post('/conversations/{conversation}/messages', [MessengerController::class, 'send'])->middleware('throttle:30,1')->name('send');
    Route::get('/conversations/{conversation}/poll', [MessengerController::class, 'poll'])->middleware('throttle:60,1')->name('poll');
    Route::post('/conversations/{conversation}/read', [MessengerController::class, 'markRead'])->middleware('throttle:60,1')->name('read');
    Route::get('/conversations/{conversation}/messages/{message}/attachments/{index}', [MessengerController::class, 'attachment'])->whereNumber('index')->middleware('throttle:30,1')->name('attachment');
});
