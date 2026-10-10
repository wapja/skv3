<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\HandoffController;
use App\Http\Controllers\Auth\SendPasswordResetLinkController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

Route::get('/', fn (): RedirectResponse => to_route(auth()->check() ? 'dashboard' : 'login'))->name('home');

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

// §6.4: any host; the token is bound to its target host.
Route::get('auth/handoff/{token}', HandoffController::class)
    ->middleware('throttle:handoff')
    ->name('auth.handoff');

require __DIR__.'/settings.php';
