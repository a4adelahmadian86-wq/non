<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

return Application::configure(basePath:dirname(__DIR__))
    ->withRouting(web:__DIR__.'/../routes/web.php',commands:__DIR__.'/../routes/console.php',health:'/up')
    ->withMiddleware(function(Middleware $middleware):void{
        TrimStrings::skipWhen(fn(Request $request):bool=>$request->is('editor/save')&&$request->has('document_model'));
        $middleware->alias(['single.editor'=>App\Http\Middleware\EnsureSingleEditor::class,'admin'=>App\Http\Middleware\AdminOnly::class,'capability'=>App\Http\Middleware\EnsureCapability::class,'permission'=>App\Http\Middleware\EnsurePermission::class]);
        $middleware->append(App\Http\Middleware\EnsurePrivateStorageDisk::class);
        if(config('farast.observability.enabled',true))$middleware->append(App\Http\Middleware\FarastObservability::class);
    })
    ->withExceptions(function(Exceptions $exceptions):void{
        $exceptions->shouldRenderJsonWhen(fn($request)=>$request->is('api/*')||$request->expectsJson());
        $exceptions->context(fn()=>['farast_request_id'=>request()?->header('X-Request-Id'),'farast_correlation_id'=>request()?->header('X-Correlation-Id')]);
    })->create();