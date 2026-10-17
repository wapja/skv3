<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\HandoffController;
use App\Http\Controllers\Auth\QuickLoginController;
use App\Http\Controllers\LocaleController;
use App\Models\User;
use App\Setup\SetupMode;
use App\Support\Skv;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

// §6.2: guests sign in; super admins on a central host start at the organisations index (TEN-1);
// everyone else (and super admins on a tenant host) lands on the dashboard. In setup mode a direct visitor goes
// to the wizard, with the token, until a super admin exists (§5.3.4); on a host the wizard cannot pin, `/` names
// the address to use instead, never with the token (RL-5).
Route::get('/', function (): RedirectResponse|Response {
    // G6-07: one look at setup mode, the token, the super admin, the pin and the host.
    $setup = app(SetupMode::class)->home(request());

    // §5.3.4: before setup, `/` leads to the wizard.
    if ($setup['redirect'] !== null) {
        return redirect($setup['redirect']);
    }

    // g3-34: a token-less redirect to the address the wizard pins; g3-10, g3-28: no address would do, so say why.
    if ($setup['problem'] !== null) {
        return $setup['problem']['suggestion'] !== null
            ? redirect()->away(rtrim($setup['problem']['suggestion'], '/').'/')
            : response()->view('setup.host-problem', ['problem' => $setup['problem']['problem']], 409);
    }

    $user = auth()->user();

    if (! $user instanceof User) {
        return to_route('login');
    }

    return to_route($user->isSuperAdmin() && ! Tenant::check() ? 'organisations.index' : 'dashboard');
})->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

// §6.4: any host; the token is bound to its target host. The GET consumes it for a guest or the same
// user and asks first when another user is signed in; only the CSRF-protected POST replaces a session.
Route::get('auth/handoff/{token}', [HandoffController::class, 'show'])
    ->middleware('throttle:handoff')
    ->name('auth.handoff');
Route::post('auth/handoff/{token}', [HandoffController::class, 'store'])
    ->middleware('throttle:handoff')
    ->name('auth.handoff.store');

// §6.5: the local quick login for the demo accounts (RL-9); QuickLogin decides, so it answers 404 elsewhere.
Route::post('quick-login', QuickLoginController::class)->middleware(['guest', 'throttle:10,1'])->name('quick-login');

// §6.5: the language toggle on every host, for guests (session) and users (account).
Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale.update');

require __DIR__.'/fortify.php';
require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/setup.php';

// §6.8: the optional impersonation module registers its routes only while it is on.
if (Skv::impersonationEnabled()) {
    require __DIR__.'/impersonation.php';
}

// §6.8: the optional passkeys module's routes.
if (Skv::passkeysEnabled()) {
    require __DIR__.'/passkeys.php';
}
