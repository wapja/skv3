<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\RoutePath;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;

// §6.8: the optional passkeys module's own routes; routes/web.php requires this file only while it is on.
// Fortify registers the passkey ceremony routes itself, only while Features::passkeys() is enabled (RL-3).
Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');

// CD-3: Fortify's passkey.destroy, with the same domain, prefix and middleware, plus a per-user limit on every request.
// Here and not in routes/fortify.php, because Fortify registers the passkey routes only while the module is on.
Route::group(['domain' => config('fortify.domain'), 'prefix' => config('fortify.prefix')], function (): void {
    Route::delete(RoutePath::for('passkey.destroy', '/user/passkeys/{passkey}'), [PasskeyRegistrationController::class, 'destroy'])
        ->middleware(['auth:web', 'throttle:account-security', 'password.confirm'])
        ->name('passkey.destroy');
});
