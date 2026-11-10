<?php

use App\Http\Controllers\AdminProductOperationsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/store/operations', [AdminProductOperationsController::class, 'index'])->name('store.operations');
    Route::post('/store/operations/documents/{document}/retry', [AdminProductOperationsController::class, 'retry'])
        ->middleware('throttle:10,10')
        ->name('store.operations.documents.retry');
});
