<?php

use App\Http\Controllers\AdminCourseResourceIngestionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/store/{product}/course/lessons/{lesson}/resources/preflight', [AdminCourseResourceIngestionController::class, 'preflight'])
        ->middleware('throttle:10,10')
        ->name('store.course.resources.preflight');
    Route::post('/store/{product}/course/lessons/{lesson}/resources/{ingestionId}/confirm', [AdminCourseResourceIngestionController::class, 'confirm'])
        ->middleware('throttle:10,10')
        ->name('store.course.resources.confirm');
    Route::delete('/store/{product}/course/lessons/{lesson}/resources/{ingestionId}/preflight', [AdminCourseResourceIngestionController::class, 'cancel'])
        ->middleware('throttle:20,10')
        ->name('store.course.resources.cancel');
});
