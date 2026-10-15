<?php

declare(strict_types=1);

/**
 * covers §6.2
 *
 * Route × host × role matrix. Every milestone adds its named routes to matrixRoutes() and,
 * where the rule differs, to routeMatrixOutcome().
 */

use App\Models\Organisation;
use App\Models\User;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthReport;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/** @return list<string> */
function matrixRoutes(): array
{
    return [
        'home', 'login', 'password.request', 'two-factor.login', 'password.confirm',
        'dashboard', 'profile.edit', 'appearance.edit', 'security.edit', 'users.index', 'users.edit',
        'users.invite', 'invitations.pending', 'organisations.index', 'organisations.create', 'organisations.edit',
        'roles.index', 'activity.index', 'system.index',
    ];
}

dataset('matrix routes', matrixRoutes());
dataset('matrix actors', ['guest', 'member', 'organisation admin', 'super admin']);
dataset('matrix hosts', ['tenant', 'apex', 'admin']);

/** @return 'ok'|'login'|'dashboard'|'confirm'|'forbidden'|'organisations'|'not found' */
function routeMatrixOutcome(string $route, string $actor, string $host): string
{
    $signedIn = $actor !== 'guest';
    $belongsHere = $actor === 'super admin' || $host === 'tenant';

    return match (true) {
        $signedIn && ! $belongsHere => 'login',           // membership middleware logs out first
        $route === 'home' => match (true) {
            ! $signedIn => 'login',
            $actor === 'super admin' && $host !== 'tenant' => 'organisations', // TEN-1
            default => 'dashboard',
        },
        in_array($route, ['login', 'password.request', 'two-factor.login'], true) => match (true) {
            $signedIn => 'dashboard',                     // guest-only pages
            $route === 'two-factor.login' => 'login',     // no challenge in the session
            default => 'ok',
        },
        ! $signedIn => 'login',
        in_array($route, ['organisations.create', 'system.index'], true) && $host === 'tenant' => 'not found', // central pages; host before can:
        $route === 'security.edit' => 'confirm',
        $actor === 'member' && in_array($route, [
            'users.index', 'users.edit', 'users.invite', 'invitations.pending', 'organisations.index',
            'organisations.edit', 'roles.index', 'activity.index',
        ], true) => 'forbidden',                          // the matrix member holds no permission
        default => 'ok',
    };
}

/**
 * Route parameters for parameter pages (RL-10), created before the host switch (strict mode).
 *
 * @return array<string, int>
 */
function matrixParameters(string $route, Organisation $organisation): array
{
    return match ($route) {
        'users.edit' => ['user' => User::factory()->for($organisation)->create()->id],
        'organisations.edit' => ['organisation' => $organisation->id],
        default => [],
    };
}

it('answers every route on every host for every role', function (string $route, string $actor, string $host): void {
    $organisation = Organisation::factory()->create();
    $parameters = matrixParameters($route, $organisation);

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

    if ($route === 'system.index') {
        // The System page runs the health report: point it at a sandbox, never the repository's .env or backups.
        $sandbox = setupSandbox();
        app()->instance(Toolchain::class, new Toolchain($sandbox->path('home')));
        app()->instance(HealthReport::class, new HealthReport(basePath: $sandbox->basePath));
        Storage::fake('backups');
    }

    $response = $this->get(route($route, $parameters));

    match (routeMatrixOutcome($route, $actor, $host)) {
        'ok' => $response->assertOk(),
        'login' => $response->assertRedirect(route('login')),
        'dashboard' => $response->assertRedirect(route('dashboard')),
        'confirm' => $response->assertRedirect(route('password.confirm')),
        'forbidden' => $response->assertForbidden(),
        'organisations' => $response->assertRedirect(route('organisations.index')),
        'not found' => $response->assertNotFound(),
    };
})->with('matrix routes')->with('matrix actors')->with('matrix hosts');

it('lists every named GET route in the matrix or the exclusions', function (): void {
    // Not pages: JSON endpoints, assets, and routes that need a token or a path parameter.
    $excluded = [
        'well-known.passkeys', 'password.reset', 'password.confirmation', 'passkey.confirm-options',
        'passkey.login-options', 'passkey.registration-options', 'two-factor.qr-code',
        'two-factor.secret-key', 'two-factor.recovery-codes', 'storage.local', '__flux.flag', 'email-change.confirm',
        'auth.handoff', 'invitations.accept',
        // §5.3.4: local-only setup routes, covered by tests/Feature/Setup/Wizard
        'setup.wizard', 'setup.herd-status', 'setup.finish', 'setup.login',
        // §4.9: the monitoring endpoint, outside the web group, covered by tests/Feature/Health
        'health-check',
    ];

    $named = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true))
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && ! str_starts_with($name, 'livewire.'))
        ->values();

    expect($named->diff([...matrixRoutes(), ...$excluded])->values()->all())->toBe([]);
});
