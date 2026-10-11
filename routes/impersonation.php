<?php

declare(strict_types=1);

use App\Http\Controllers\ImpersonationController;
use Illuminate\Support\Facades\Route;

// §4.7 / §6.8: the impersonation module's routes; routes/web.php requires this file only while the module
// is on. Starting is a users-index action on a tenant host; stopping works on any host.
Route::middleware('auth')
    ->delete('impersonation', [ImpersonationController::class, 'destroy'])
    ->name('impersonation.stop');
