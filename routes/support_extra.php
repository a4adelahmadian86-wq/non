<?php

use App\Http\Controllers\SupportReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/support-report', SupportReportController::class)->name('support.report');
});
