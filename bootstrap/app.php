<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'single.editor' => App\Http\Middleware\EnsureSingleEditor::class,
            'admin' => App\Http\Middleware\AdminOnly::class,
            'capability' => App\Http\Middleware\EnsureCapability::class,
        ]);
        $middleware->append(App\Http\Middleware\EnsurePrivateStorageDisk::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })
    ->create();
