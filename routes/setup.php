<?php

declare(strict_types=1);

use App\Setup\Http\EnsureSetupAccess;
use Illuminate\Support\Facades\Route;

// §5.3.4: the browser wizard, on every host. EnsureSetupAccess answers 404 unless the app is in setup mode (RL-1),
// the request is direct and it carries the one-time token (RL-2).
Route::livewire('welcome-setup', 'pages::setup.wizard')
    ->middleware(EnsureSetupAccess::class)
    ->name('setup.wizard');
