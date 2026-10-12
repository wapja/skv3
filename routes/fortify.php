<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SendPasswordResetLinkController;
use App\Tenancy\Http\Middleware\StopImpersonationBeforeLogout;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\RoutePath;

/*
| The kit's replacements for four Fortify routes (§4.2, §4.7). Same method, domain, prefix and path as Fortify
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

    // §4.2 / R1: throttled, so reset tokens and addresses cannot be probed.
    Route::post(RoutePath::for('password.update', '/reset-password'), [NewPasswordController::class, 'store'])
        ->middleware(['guest:web', 'throttle:password-reset'])
        ->name('password.update');

    // §4.7: signing out while impersonating ends the impersonation first.
    Route::post(RoutePath::for('logout', '/logout'), [AuthenticatedSessionController::class, 'destroy'])
        ->middleware(['auth:web', StopImpersonationBeforeLogout::class])
        ->name('logout');
});
