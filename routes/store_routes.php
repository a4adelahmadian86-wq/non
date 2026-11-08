<?php

use App\Http\Controllers\AdminCourseController;
use App\Http\Controllers\AdminProductSupervisorController;
use App\Http\Controllers\AdminStoreController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreCourseLearningController;
use App\Http\Controllers\StoreWishlistController;
use Illuminate\Support\Facades\Route;

Route::get('/store/product/{slug}/reader',[StoreController::class,'reader'])->name('store.reader');
Route::middleware(['auth'])->group(function () {
    Route::get('/store/product/{product}/reader/pages/{page}', [StoreController::class, 'readerPage'])->whereNumber('page')->middleware('throttle:300,10')->name('store.reader.page');
    Route::get('/store/product/{product}/reader/audio/{document}', [StoreController::class, 'readerAudio'])->middleware('throttle:300,10')->name('store.reader.audio');
});
Route::get('/store/preview/{preview}/audio', [StoreController::class, 'previewAudio'])->middleware('throttle:60,10')->name('store.preview.audio');
Route::get('/store/product/{product}/lessons/{lesson}/preview',[StoreController::class,'coursePreview'])->name('store.course.preview');

Route::middleware(['auth'])->group(function () {
    Route::get('/store/{product}/reader-state', [\App\Http\Controllers\StoreReaderStateController::class, 'show'])->middleware('throttle:120,10')->name('store.reader.state.show');
    Route::put('/store/{product}/reader-state', [\App\Http\Controllers\StoreReaderStateController::class, 'update'])->middleware('throttle:60,10')->name('store.reader.state.update');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/my-courses/{product}', [StoreCourseLearningController::class, 'show'])->name('store.course.learn');
    Route::get('/my-courses/{product}/lessons/{lesson}', [StoreCourseLearningController::class, 'lesson'])->name('store.course.lesson');
    Route::post('/my-courses/{product}/lessons/{lesson}/progress', [StoreCourseLearningController::class, 'progress'])->middleware('throttle:60,10')->name('store.course.progress');
    Route::get('/my-courses/{product}/lessons/{lesson}/resources/{file}', [StoreCourseLearningController::class, 'resource'])->middleware('throttle:60,10')->name('store.course.resource');
});

Route::middleware('auth')->group(function () {
    Route::get('/wishlist',[StoreWishlistController::class,'index'])->name('wishlist');
    Route::post('/wishlist/{product}',[StoreWishlistController::class,'toggle'])->middleware('throttle:60,10')->name('wishlist.toggle');
    Route::delete('/wishlist/{product}',[StoreWishlistController::class,'destroy'])->middleware('throttle:60,10')->name('wishlist.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/checkout/{order}/zarinpal',[PaymentController::class,'payWithZarinpal'])->middleware('throttle:10,10')->name('checkout.zarinpal');
    Route::get('/checkout/zarinpal/callback',[PaymentController::class,'zarinpalCallback'])->middleware('throttle:30,10')->name('checkout.zarinpal.callback');
});

Route::get('/wallet/top-up/zarinpal/callback',[\App\Http\Controllers\WalletController::class,'topUpCallback'])->middleware('throttle:30,10')->name('wallet.topup.callback');

Route::middleware(['auth','admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/store/{product}/ai-supervisor/review', [\App\Http\Controllers\AdminProductSupervisorController::class, 'review'])->middleware('throttle:5,10')->name('store.ai-supervisor.review');
    Route::post('/store/{product}/ai-supervisor/files/{file}/review', [\App\Http\Controllers\AdminProductSupervisorController::class, 'reviewFile'])->middleware('throttle:5,10')->name('store.ai-supervisor.files.review');
    Route::post('/store/{product}/ai-supervisor/images/{image}/review', [\App\Http\Controllers\AdminProductSupervisorController::class, 'reviewImage'])->middleware('throttle:5,10')->name('store.ai-supervisor.images.review');
    Route::post('/store/{product}/ai-supervisor/cover/review', [\App\Http\Controllers\AdminProductSupervisorController::class, 'reviewCover'])->middleware('throttle:5,10')->name('store.ai-supervisor.cover.review');
    Route::get('/store',[AdminStoreController::class,'index'])->name('store.index');
    Route::get('/store/create',[AdminStoreController::class,'create'])->name('store.create');
    Route::post('/store',[AdminStoreController::class,'store'])->name('store.store');
    Route::get('/store/{product}/edit',[AdminStoreController::class,'edit'])->name('store.edit');
    Route::put('/store/{product}',[AdminStoreController::class,'update'])->name('store.update');
    Route::delete('/store/{product}',[AdminStoreController::class,'destroy'])->name('store.destroy');
    Route::post('/store/{product}/cover',[AdminStoreController::class,'uploadCover'])->middleware('throttle:10,10')->name('store.cover.upload');
    Route::post('/store/{product}/images',[AdminStoreController::class,'uploadImage'])->middleware('throttle:20,10')->name('store.images.upload');
    Route::delete('/store/{product}/images/{image}',[AdminStoreController::class,'deleteImage'])->name('store.images.delete');
    Route::post('/store/{product}/files/preflight',[AdminStoreController::class,'preflightFile'])->middleware('throttle:10,10')->name('store.files.preflight');
    Route::get('/store/{product}/files/{file}/processing',[AdminStoreController::class,'fileProcessingStatus'])->middleware('throttle:60,10')->name('store.files.processing');
    Route::post('/store/{product}/files/{ingestionId}/confirm',[AdminStoreController::class,'confirmFileUpload'])->middleware('throttle:10,10')->name('store.files.confirm');
    Route::delete('/store/{product}/files/{ingestionId}/preflight',[AdminStoreController::class,'cancelFilePreflight'])->middleware('throttle:20,10')->name('store.files.cancel');
    Route::post('/store/{product}/files',[AdminStoreController::class,'uploadFile'])->middleware('throttle:10,10')->name('store.files.upload');
    Route::delete('/store/{product}/files/{file}',[AdminStoreController::class,'deleteFile'])->name('store.files.delete');
    Route::post('/store/{product}/previews',[AdminStoreController::class,'uploadPreview'])->middleware('throttle:10,10')->name('store.previews.upload');
    Route::post('/store/{product}/previews/{preview}/audio', [AdminStoreController::class, 'uploadPreviewAudio'])->middleware('throttle:10,10')->name('store.previews.audio.upload');
    Route::delete('/store/{product}/previews/{preview}',[AdminStoreController::class,'deletePreview'])->name('store.previews.delete');

    Route::get('/store/{product}/course',[AdminCourseController::class,'edit'])->name('store.course.edit');
    Route::put('/store/{product}/course',[AdminCourseController::class,'update'])->name('store.course.update');
    Route::post('/store/{product}/course/sections',[AdminCourseController::class,'storeSection'])->name('store.course.sections.store');
    Route::put('/store/{product}/course/sections/{section}',[AdminCourseController::class,'updateSection'])->name('store.course.sections.update');
    Route::delete('/store/{product}/course/sections/{section}',[AdminCourseController::class,'deleteSection'])->name('store.course.sections.delete');
    Route::post('/store/{product}/course/sections/{section}/lessons',[AdminCourseController::class,'storeLesson'])->name('store.course.lessons.store');
    Route::put('/store/{product}/course/lessons/{lesson}',[AdminCourseController::class,'updateLesson'])->name('store.course.lessons.update');
    Route::delete('/store/{product}/course/lessons/{lesson}',[AdminCourseController::class,'deleteLesson'])->name('store.course.lessons.delete');
    Route::post('/store/{product}/course/lessons/{lesson}/resources',[AdminCourseController::class,'uploadResource'])->middleware('throttle:10,10')->name('store.course.resources.upload');
    Route::delete('/store/{product}/course/lessons/{lesson}/resources/{file}',[AdminCourseController::class,'deleteResource'])->name('store.course.resources.delete');
    Route::post('/store/{product}/course/lessons/{lesson}/resources/{file}/review',[AdminProductSupervisorController::class,'reviewCourseResource'])->middleware('throttle:5,10')->name('store.course.resources.review');

    Route::post('/store/categories',[AdminStoreController::class,'storeCategory'])->name('store.categories.store');
    Route::post('/store/tags',[AdminStoreController::class,'storeTag'])->name('store.tags.store');
});
