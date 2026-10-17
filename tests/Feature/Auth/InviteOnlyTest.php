<?php

declare(strict_types=1);

/** covers §4.2.2, §4.4.6, §4.10.3 */

use App\Http\Controllers\ImpersonationController;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;

it('has no registration', function (): void {
    expect(Route::has('register'))->toBeFalse();
    $this->get('/register')->assertNotFound();
});

it('has no email verification', function (): void {
    expect(Route::has('verification.notice'))->toBeFalse()
        ->and(Route::has('verification.verify'))->toBeFalse();
});

it('offers no self-service account deletion', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('delete-user', escape: false);

    $deleteViews = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file): bool => str_contains($file->getPathname(), 'delete-user'));

    expect($deleteViews)->toBeEmpty();
});

it('registers no DELETE route other than disabling 2FA, removing a passkey and, with the module on, stopping an impersonation', function (): void {
    usePasskeysModule(true);
    $deleteRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('DELETE', $route->methods(), true))
        ->mapWithKeys(fn (RoutingRoute $route): array => [$route->uri() => ltrim($route->getActionName(), '\\')])
        ->sortKeys()
        ->all();

    // Route::redirect() registers an "any" route, so the settings redirect also accepts DELETE.
    $expected = [
        'settings' => RedirectController::class,
        'user/passkeys/{passkey}' => PasskeyRegistrationController::class.'@destroy',
        'user/two-factor-authentication' => TwoFactorAuthenticationController::class.'@destroy',
    ];

    // §6.8: routes/web.php registers the impersonation module's stop route only while the module is on.
    if (Impersonation::enabled()) {
        $expected['impersonation'] = ImpersonationController::class.'@destroy';
    }

    ksort($expected);

    expect($deleteRoutes)->toBe($expected);
});

it('sends guests from the root url to the login page', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends guests from the dashboard to the login page', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
