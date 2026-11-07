<?php

use App\Http\Controllers\SupportController;
use App\Http\Controllers\SupportReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/support-report', SupportReportController::class)->name('support.report');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/support/tickets/{ticket}/messages/{message}/attachments/{index}', [SupportController::class, 'attachment'])->whereNumber('index')->middleware('throttle:60,10')->name('support.attachment');
    Route::post('/support/tickets/{ticket}/csat', [SupportController::class, 'csat'])->name('support.csat');
});
