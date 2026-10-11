<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\HandoffController;
use App\Http\Controllers\Auth\SendPasswordResetLinkController;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

// §6.2: guests sign in; super admins on a central host start at the organisations index (TEN-1);
// everyone else (and super admins on a tenant host) lands on the dashboard.
Route::get('/', function (): RedirectResponse {
    $user = auth()->user();

    if (! $user instanceof User) {
        return to_route('login');
    }

    return to_route($user->isSuperAdmin() && ! Tenant::check() ? 'organisations.index' : 'dashboard');
})->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// §4.2: password confirmation is throttled. Same method, URI and name as Fortify's route, so this one replaces it.
Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware(['auth:web', 'throttle:password-confirm'])
    ->name('password.confirm.store');

// §4.2 / R1: throttled, neutral and constant-time. Same method, URI and name as Fortify's route, so this one replaces it.
Route::post('forgot-password', SendPasswordResetLinkController::class)
    ->middleware(['guest:web', 'throttle:password-email'])
    ->name('password.email');

// §4.2 / R1: throttled, so reset tokens and addresses cannot be probed. Same method, URI and name as Fortify's route.
Route::post('reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['guest:web', 'throttle:password-reset'])
    ->name('password.update');

// §6.4: any host; the token is bound to its target host. The GET consumes it for a guest or the same
// user and asks first when another user is signed in; only the CSRF-protected POST replaces a session.
Route::get('auth/handoff/{token}', [HandoffController::class, 'show'])
    ->middleware('throttle:handoff')
    ->name('auth.handoff');
Route::post('auth/handoff/{token}', [HandoffController::class, 'store'])
    ->middleware('throttle:handoff')
    ->name('auth.handoff.store');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';

// §6.8: the optional impersonation module registers its routes only while it is on.
if (Impersonation::enabled()) {
    require __DIR__.'/impersonation.php';
}
