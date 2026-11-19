<?php

use App\Http\Controllers\StorePriceProposalController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/store/price-proposals', [StorePriceProposalController::class, 'index'])
        ->name('store.price-proposals.index');
    Route::post('/store/price-proposals/{proposal}/accept', [StorePriceProposalController::class, 'accept'])
        ->name('store.price-proposals.accept');
    Route::post('/store/price-proposals/{proposal}/reject', [StorePriceProposalController::class, 'reject'])
        ->name('store.price-proposals.reject');
});

Route::middleware(['auth', 'admin', 'admin.section:store'])
    ->prefix('admin/store')
    ->name('admin.store.')
    ->group(function () {
        Route::get('/price-proposals', [StorePriceProposalController::class, 'adminIndex'])
            ->name('price-proposals.index');
        Route::post('/products/{product}/price-proposal', [StorePriceProposalController::class, 'create'])
            ->name('price-proposals.create');
    });
