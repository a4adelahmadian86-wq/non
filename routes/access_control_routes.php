<?php

use App\Http\Controllers\AdminAccessCenterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin', 'admin.section:access'])
    ->prefix('admin/access-center')
    ->name('admin.access-center.')
    ->group(function (): void {
        Route::get('/', [AdminAccessCenterController::class, 'index'])->name('index');
        Route::post('/roles', [AdminAccessCenterController::class, 'storeRole'])->middleware('throttle:20,10')->name('roles.store');
        Route::put('/roles/{role}', [AdminAccessCenterController::class, 'updateRole'])->whereNumber('role')->middleware('throttle:30,10')->name('roles.update');
        Route::delete('/roles/{role}', [AdminAccessCenterController::class, 'destroyRole'])->whereNumber('role')->middleware('throttle:10,10')->name('roles.destroy');
        Route::post('/roles/{role}/permissions', [AdminAccessCenterController::class, 'syncPermissions'])->whereNumber('role')->middleware('throttle:20,10')->name('roles.permissions');
        Route::post('/users/{user}/roles', [AdminAccessCenterController::class, 'assignRole'])->whereNumber('user')->middleware('throttle:20,10')->name('users.roles.store');
        Route::delete('/users/{user}/roles/{assignment}', [AdminAccessCenterController::class, 'revokeRole'])->whereNumber('user')->whereNumber('assignment')->middleware('throttle:30,10')->name('users.roles.destroy');
        Route::post('/users/{user}/overrides', [AdminAccessCenterController::class, 'storeOverride'])->whereNumber('user')->middleware('throttle:20,10')->name('users.overrides.store');
        Route::delete('/users/{user}/overrides/{override}', [AdminAccessCenterController::class, 'revokeOverride'])->whereNumber('user')->whereNumber('override')->middleware('throttle:30,10')->name('users.overrides.destroy');
        Route::get('/users/{user}/decisions/{permission}', [AdminAccessCenterController::class, 'explain'])->whereNumber('user')->middleware('throttle:60,10')->name('users.decisions.show');
    });
