<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// §6.8: the optional passkeys module's own routes; routes/web.php requires this file only while it is on.
// Fortify registers the passkey ceremony routes itself, only while Features::passkeys() is enabled (RL-3).
Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');
