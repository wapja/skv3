<?php

declare(strict_types=1);

use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Http\Middleware\EnsureCentralHost;
use App\Tenancy\Http\Middleware\EnsureTenantHost;
use App\Tenancy\Http\Middleware\InitializeTenancy;
use App\Tenancy\Http\Middleware\VerifyTenantMembership;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [VerifyTenantMembership::class], prepend: [InitializeTenancy::class]);
        // Before route-model binding (and so before can:): a tenant model bound on the wrong host
        // gives a 404, not a strict-mode error.
        $middleware->prependToPriorityList(SubstituteBindings::class, VerifyTenantMembership::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureTenantHost::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureCentralHost::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([TenantNotFoundException::class]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
