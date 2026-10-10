<?php

declare(strict_types=1);

use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Http\Middleware\InitializeTenancy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [InitializeTenancy::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([TenantNotFoundException::class]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
