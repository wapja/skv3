<?php

declare(strict_types=1);

use App\Http\Controllers\Setup\SetupFinishController;
use App\Http\Controllers\Setup\SetupHerdStatusController;
use App\Http\Controllers\Setup\SetupLoginController;
use App\Http\Middleware\SetReferrerPolicy;
use App\Setup\Http\EnsureSetupAccess;
use Illuminate\Support\Facades\Route;

// §5.3.4: the browser wizard, on every host. EnsureSetupAccess answers 404 unless the app is in setup mode (RL-1),
// the request is direct and it carries the one-time token (RL-2). The token's URL sends no referrer (g3-13).
Route::livewire('welcome-setup', 'pages::setup.wizard')
    ->middleware([SetReferrerPolicy::class, EnsureSetupAccess::class])
    ->name('setup.wizard');

// RL-6: the Herd stage, after the marker; each endpoint needs the secret only the wizard's browser holds, so none
// sends a referrer (g3-13).
Route::get('welcome-setup/herd-status', SetupHerdStatusController::class)->middleware([SetReferrerPolicy::class, 'throttle:120,1'])->name('setup.herd-status');
Route::get('welcome-setup/finish', SetupFinishController::class)->middleware([SetReferrerPolicy::class, 'throttle:60,1'])->name('setup.finish');
Route::get('welcome-setup/login', SetupLoginController::class)->middleware([SetReferrerPolicy::class, 'throttle:10,1'])->name('setup.login');
