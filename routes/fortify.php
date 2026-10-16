<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SendPasswordResetLinkController;
use App\Tenancy\Http\Middleware\StopImpersonationBeforeLogout;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\RoutePath;

/*
| The kit's replacements for eight Fortify routes (§4.2, §4.7). Same method, domain, prefix and path as Fortify
| (RoutePath honours fortify.paths), so each replaces the vendor route even with a custom prefix or domain (FU3-3).
*/
Route::group(['domain' => config('fortify.domain'), 'prefix' => config('fortify.prefix')], function (): void {
    // §4.2: password confirmation is throttled.
    Route::post(RoutePath::for('password.confirm', '/user/confirm-password'), [ConfirmablePasswordController::class, 'store'])
        ->middleware(['auth:web', 'throttle:password-confirm'])
        ->name('password.confirm.store');

    // §4.2 / R1: throttled, neutral and constant-time.
    Route::post(RoutePath::for('password.email', '/forgot-password'), SendPasswordResetLinkController::class)
        ->middleware(['guest:web', 'throttle:password-email'])
        ->name('password.email');

    // §4.2 / R1: throttled, so reset tokens and addresses cannot be probed; G1-02: an array address is a validation error.
    Route::post(RoutePath::for('password.update', '/reset-password'), [ResetPasswordController::class, 'store'])
        ->middleware(['guest:web', 'throttle:password-reset'])
        ->name('password.update');

    // CD-3: the two-factor management routes, as Fortify registers them, plus a per-user limit on every request.
    Route::middleware(['auth:web', 'throttle:account-security', 'password.confirm'])->group(function (): void {
        Route::post(RoutePath::for('two-factor.enable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.enable');
        Route::post(RoutePath::for('two-factor.confirm', '/user/confirmed-two-factor-authentication'), [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.confirm');
        Route::delete(RoutePath::for('two-factor.disable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('two-factor.disable');
        Route::post(RoutePath::for('two-factor.recovery-codes', '/user/two-factor-recovery-codes'), [RecoveryCodeController::class, 'store'])
            ->name('two-factor.regenerate-recovery-codes');
    });

    // §4.7: signing out while impersonating ends the impersonation first.
    Route::post(RoutePath::for('logout', '/logout'), [AuthenticatedSessionController::class, 'destroy'])
        ->middleware(['auth:web', StopImpersonationBeforeLogout::class])
        ->name('logout');
});
