<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Tenancy\Http\Middleware\StopImpersonationBeforeLogout;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

it('keeps the throttled kit routes in place of Fortify\'s when Fortify has a prefix (FU3-3, CD-3)', function (): void {
    config(['fortify.prefix' => 'auth', 'fortify.domain' => null]);

    $scratch = new Router(app('events'), app());
    $original = app(Router::class);
    Route::swap($scratch);

    try {
        $scratch->group([
            'namespace' => 'Laravel\Fortify\Http\Controllers',
            'domain' => config('fortify.domain'),
            'prefix' => config('fortify.prefix'),
        ], base_path('vendor/laravel/fortify/routes/routes.php'));
        $scratch->middleware('web')->group(base_path('routes/fortify.php'));
    } finally {
        Route::swap($original);
    }

    $routes = $scratch->getRoutes();
    $routes->refreshNameLookups();

    foreach ([
        'password.email' => ['auth/forgot-password', 'throttle:password-email'],
        'password.update' => ['auth/reset-password', 'throttle:password-reset'],
        'password.confirm.store' => ['auth/user/confirm-password', 'throttle:password-confirm'],
        'two-factor.enable' => ['auth/user/two-factor-authentication', 'throttle:account-security'],
        'two-factor.confirm' => ['auth/user/confirmed-two-factor-authentication', 'throttle:account-security'],
        'two-factor.disable' => ['auth/user/two-factor-authentication', 'throttle:account-security'],
        'two-factor.regenerate-recovery-codes' => ['auth/user/two-factor-recovery-codes', 'throttle:account-security'],
    ] as $name => [$uri, $throttle]) {
        $route = $routes->getByName($name);

        expect($route?->uri())->toBe($uri)
            ->and($route?->gatherMiddleware())->toContain($throttle);
    }

    expect($routes->getByName('logout')?->gatherMiddleware())
        ->toContain(StopImpersonationBeforeLogout::class);
});
