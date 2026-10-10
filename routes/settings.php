<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\ConfirmEmailChangeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(['password.confirm'])
        ->name('security.edit');

    Route::get('settings/email/confirm/{user}', ConfirmEmailChangeController::class)
        ->middleware('signed:relative')
        ->whereNumber('user')
        ->name('email-change.confirm');
});

Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');
