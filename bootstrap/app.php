<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['member' => \App\Http\Middleware\MemberAccess::class, 'admin' => \App\Http\Middleware\PlatformAdmin::class, 'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
            // Rate limits counted per route (Laravel's default shares one counter across all throttled routes).
            'throttle' => \App\Http\Middleware\ThrottlePerRoute::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
