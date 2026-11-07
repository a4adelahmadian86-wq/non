<?php

use App\Http\Controllers\AdminCourseController;
use App\Http\Controllers\AdminStoreController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreCourseLearningController;
use App\Http\Controllers\StoreWishlistController;
use Illuminate\Support\Facades\Route;

Route::get('/store/product/{slug}/reader',[StoreController::class,'reader'])->name('store.reader');

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
    Route::get('/store',[AdminStoreController::class,'index'])->name('store.index');
    Route::get('/store/create',[AdminStoreController::class,'create'])->name('store.create');
    Route::post('/store',[AdminStoreController::class,'store'])->name('store.store');
    Route::get('/store/{product}/edit',[AdminStoreController::class,'edit'])->name('store.edit');
    Route::put('/store/{product}',[AdminStoreController::class,'update'])->name('store.update');
    Route::delete('/store/{product}',[AdminStoreController::class,'destroy'])->name('store.destroy');
    Route::post('/store/{product}/files',[AdminStoreController::class,'uploadFile'])->middleware('throttle:10,10')->name('store.files.upload');
    Route::delete('/store/{product}/files/{file}',[AdminStoreController::class,'deleteFile'])->name('store.files.delete');
    Route::post('/store/{product}/previews',[AdminStoreController::class,'uploadPreview'])->middleware('throttle:10,10')->name('store.previews.upload');
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

    Route::post('/store/categories',[AdminStoreController::class,'storeCategory'])->name('store.categories.store');
});
