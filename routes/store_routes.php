<?php

use App\Http\Controllers\AdminStoreController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreWishlistController;
use Illuminate\Support\Facades\Route;

Route::get('/store/product/{slug}/reader',[StoreController::class,'reader'])->name('store.reader');

Route::middleware('auth')->group(function () {
    Route::get('/wishlist',[StoreWishlistController::class,'index'])->name('wishlist');
    Route::post('/wishlist/{product}',[StoreWishlistController::class,'toggle'])->middleware('throttle:60,10')->name('wishlist.toggle');
    Route::delete('/wishlist/{product}',[StoreWishlistController::class,'destroy'])->middleware('throttle:60,10')->name('wishlist.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/checkout/{order}/zarinpal',[PaymentController::class,'payWithZarinpal'])->middleware('throttle:10,10')->name('checkout.zarinpal');
    Route::get('/checkout/zarinpal/callback',[PaymentController::class,'zarinpalCallback'])->middleware('throttle:30,10')->name('checkout.zarinpal.callback');
});

Route::middleware(['auth','admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/store',[AdminStoreController::class,'index'])->name('store.index');
    Route::get('/store/create',[AdminStoreController::class,'create'])->name('store.create');
    Route::post('/store',[AdminStoreController::class,'store'])->name('store.store');
    Route::get('/store/{product}/edit',[AdminStoreController::class,'edit'])->name('store.edit');
    Route::put('/store/{product}',[AdminStoreController::class,'update'])->name('store.update');
    Route::delete('/store/{product}',[AdminStoreController::class,'destroy'])->name('store.destroy');
    Route::post('/store/{product}/files',[AdminStoreController::class,'uploadFile'])->name('store.files.upload');
    Route::delete('/store/{product}/files/{file}',[AdminStoreController::class,'deleteFile'])->name('store.files.delete');
    Route::post('/store/{product}/previews',[AdminStoreController::class,'uploadPreview'])->name('store.previews.upload');
    Route::delete('/store/{product}/previews/{preview}',[AdminStoreController::class,'deletePreview'])->name('store.previews.delete');
    Route::post('/store/categories',[AdminStoreController::class,'storeCategory'])->name('store.categories.store');
});
