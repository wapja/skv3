<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;

Route::get('/', fn (): RedirectResponse => to_route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// §4.2: password confirmation is throttled. Same method, URI and name as Fortify's route, so this one replaces it.
Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware(['auth:web', 'throttle:password-confirm'])
    ->name('password.confirm.store');

require __DIR__.'/settings.php';
