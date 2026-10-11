<?php

declare(strict_types=1);

/**
 * covers §6.2
 *
 * Route × host × role matrix. Every milestone adds its named routes to matrixRoutes() and,
 * where the rule differs, to routeMatrixOutcome().
 */

use App\Models\Organisation;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/** @return list<string> */
function matrixRoutes(): array
{
    return [
        'home', 'login', 'password.request', 'two-factor.login', 'password.confirm',
        'dashboard', 'profile.edit', 'appearance.edit', 'security.edit', 'users.index',
    ];
}

dataset('matrix routes', matrixRoutes());
dataset('matrix actors', ['guest', 'member', 'organisation admin', 'super admin']);
dataset('matrix hosts', ['tenant', 'apex', 'admin']);

/** @return 'ok'|'login'|'dashboard'|'confirm'|'forbidden' */
function routeMatrixOutcome(string $route, string $actor, string $host): string
{
    $signedIn = $actor !== 'guest';
    $belongsHere = $actor === 'super admin' || $host === 'tenant';

    return match (true) {
        $signedIn && ! $belongsHere => 'login',           // membership middleware logs out first
        $route === 'home' => $signedIn ? 'dashboard' : 'login',
        in_array($route, ['login', 'password.request', 'two-factor.login'], true) => match (true) {
            $signedIn => 'dashboard',                     // guest-only pages
            $route === 'two-factor.login' => 'login',     // no challenge in the session
            default => 'ok',
        },
        ! $signedIn => 'login',
        $route === 'security.edit' => 'confirm',
        $actor === 'member' && $route === 'users.index' => 'forbidden', // the matrix member holds no permission
        default => 'ok',
    };
}

it('answers every route on every host for every role', function (string $route, string $actor, string $host): void {
    $organisation = Organisation::factory()->create();

    match ($actor) {
        'member' => asMember($organisation),
        'organisation admin' => asOrgAdmin($organisation),
        'super admin' => asSuperAdmin(),
        default => null,
    };

    match ($host) {
        'tenant' => onTenant($organisation),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    $response = $this->get(route($route));

    match (routeMatrixOutcome($route, $actor, $host)) {
        'ok' => $response->assertOk(),
        'login' => $response->assertRedirect(route('login')),
        'dashboard' => $response->assertRedirect(route('dashboard')),
        'confirm' => $response->assertRedirect(route('password.confirm')),
        'forbidden' => $response->assertForbidden(),
    };
})->with('matrix routes')->with('matrix actors')->with('matrix hosts');

it('lists every named GET route in the matrix or the exclusions', function (): void {
    // Not pages: JSON endpoints, assets, and routes that need a token or a path parameter.
    $excluded = [
        'well-known.passkeys', 'password.reset', 'password.confirmation', 'passkey.confirm-options',
        'passkey.login-options', 'passkey.registration-options', 'two-factor.qr-code',
        'two-factor.secret-key', 'two-factor.recovery-codes', 'storage.local', '__flux.flag', 'email-change.confirm',
        'auth.handoff',
    ];

    $named = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true))
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && ! str_starts_with($name, 'livewire.'))
        ->values();

    expect($named->diff([...matrixRoutes(), ...$excluded])->values()->all())->toBe([]);
});
