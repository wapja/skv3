<?php

declare(strict_types=1);

use App\Exceptions\InvitationNotFoundException;
use App\Http\Middleware\SetLocale;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Http\Middleware\EnforceImpersonation;
use App\Tenancy\Http\Middleware\EnsureCentralHost;
use App\Tenancy\Http\Middleware\EnsureTenantHost;
use App\Tenancy\Http\Middleware\InitializeTenancy;
use App\Tenancy\Http\Middleware\VerifyTenantMembership;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Webauthn\Exception\WebauthnException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            append: [EnforceImpersonation::class, VerifyTenantMembership::class, AuthenticateSession::class, SetLocale::class],
            prepend: [InitializeTenancy::class],
        );
        // Before route-model binding (and so before can:): a tenant model bound on the wrong host
        // gives a 404, not a strict-mode error.
        $middleware->prependToPriorityList(SubstituteBindings::class, VerifyTenantMembership::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureTenantHost::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureCentralHost::class);
        // §4.7 / RL-20: a running impersonation is re-checked from the session before Authenticate (or anything
        // else) resolves the signed-in user, so a gone target never falls back to a remember-me sign-in.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnforceImpersonation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([TenantNotFoundException::class, InvitationNotFoundException::class]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // A failed WebAuthn ceremony (origin, challenge, signature, counter) is a client error, not a 500.
        $exceptions->render(fn (WebauthnException $exception): JsonResponse => new JsonResponse([
            'message' => __('This passkey could not be verified.'),
            'errors' => ['credential' => [__('This passkey could not be verified.')]],
        ], 422));
    })->create();
