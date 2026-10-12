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

    // E1 / DEC-25 (5): the link proves control of the new address and a recent password confirmation proves
    // the owner is present, so a hijacked session cannot move the account to an attacker's address (DEC-27 (7)).
    Route::get('settings/email/confirm/{user}', ConfirmEmailChangeController::class)
        ->middleware(['signed:relative', 'password.confirm'])
        ->whereNumber('user')
        ->name('email-change.confirm');
});
