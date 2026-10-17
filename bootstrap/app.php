<?php

declare(strict_types=1);

use App\Exceptions\InvitationNotFoundException;
use App\Http\Controllers\HealthCheckController;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleHealthChecks;
use App\Setup\Http\EnterSetupMode;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Webauthn\Exception\CounterException;
use Webauthn\Exception\WebauthnException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // §4.9, RL-5: outside every middleware group, so no session, cookies, tenancy, setup mode or locale.
        then: function (): void {
            Route::middleware(ThrottleHealthChecks::class)
                ->get('health-check', HealthCheckController::class)
                ->name('health-check');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            append: [EnforceImpersonation::class, VerifyTenantMembership::class, AuthenticateSession::class, SetLocale::class],
            prepend: [EnterSetupMode::class, InitializeTenancy::class],
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

        // FU3-1: a failed WebAuthn ceremony is a client error (rendered as a 422 below), not an incident to report.
        // CD-6: except a signature counter that did not increase, the sign of a cloned authenticator.
        $exceptions->dontReportWhen(fn (Throwable $exception): bool => $exception instanceof WebauthnException
            && ! $exception instanceof CounterException);

        // CD-6: one warning without a stack trace; the request, not the code, is what needs looking at. A credential
        // id is a public identifier, not a secret.
        $exceptions->report(function (CounterException $exception): void {
            $credentialId = request()->input('credential.rawId');

            Log::warning('A passkey was used with a signature counter that did not increase; the authenticator may have been cloned.', [
                'credential_id' => is_string($credentialId) ? $credentialId : null,
                'received_counter' => $exception->currentCounter,
                'stored_counter' => $exception->authenticatorCounter,
                'host' => request()->getHost(),
                'ip' => request()->ip(),
            ]);
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // A failed WebAuthn ceremony (origin, challenge, signature, counter) is a client error, not a 500.
        $exceptions->render(fn (WebauthnException $exception): JsonResponse => new JsonResponse([
            'message' => __('This passkey could not be verified.'),
            'errors' => ['credential' => [__('This passkey could not be verified.')]],
        ], 422));
    })->create();
