<?php

use App\Http\Controllers\AdminAccessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:30,1', 'permission:access.manage'])
    ->prefix('admin/access-control')
    ->name('admin.access-control.')
    ->group(function (): void {
        Route::get('/', [AdminAccessController::class, 'index'])->name('index');
        Route::post('/roles', [AdminAccessController::class, 'storeRole'])->name('roles.store');
        Route::patch('/roles/{roleKey}', [AdminAccessController::class, 'updateRole'])->name('roles.update');
        Route::post('/users/{user}/roles', [AdminAccessController::class, 'assignRole'])->name('users.roles.assign');
        Route::delete('/role-assignments/{assignment}', [AdminAccessController::class, 'revokeRole'])->name('role-assignments.revoke');
        Route::post('/users/{user}/permissions', [AdminAccessController::class, 'grantPermission'])->name('users.permissions.grant');
        Route::delete('/permission-grants/{grant}', [AdminAccessController::class, 'revokePermission'])->name('permission-grants.revoke');
        Route::post('/users/{user}/responsibility/primary', [\App\Http\Controllers\StaffLifecycleController::class, 'primary'])->name('users.responsibility.primary');
        Route::post('/users/{user}/responsibility/secondary', [\App\Http\Controllers\StaffLifecycleController::class, 'secondary'])->name('users.responsibility.secondary');
        Route::delete('/responsibility-assignments/{assignment}', [\App\Http\Controllers\StaffLifecycleController::class, 'endResponsibility'])->name('responsibility.end');
        Route::put('/users/{user}/departments', [\App\Http\Controllers\StaffLifecycleController::class, 'department'])->name('users.departments.update');
        Route::post('/users/{user}/dismiss', [\App\Http\Controllers\StaffLifecycleController::class, 'dismiss'])->name('users.dismiss');
    });
