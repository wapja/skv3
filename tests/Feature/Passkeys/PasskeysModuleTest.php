<?php

declare(strict_types=1);

/** covers §6.8 */

use App\Models\Organisation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

it('registers the passkey routes and shows passkeys only while the module is on', function (bool $enabled): void {
    usePasskeysModule($enabled);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    onTenant($organisation);

    expect(Route::has('passkey.login-options'))->toBe($enabled)
        ->and(Route::has('passkey.store'))->toBe($enabled)
        ->and(Route::has('well-known.passkeys'))->toBe($enabled)
        ->and(Features::canManagePasskeys())->toBe($enabled);

    $login = $this->get(route('login'))->assertOk();
    $enabled
        ? $login->assertSee(__('Sign in with a passkey'))
        : $login->assertDontSee(__('Sign in with a passkey'));

    $wellKnown = $this->get('/.well-known/passkey-endpoints');
    $enabled ? $wellKnown->assertOk() : $wellKnown->assertNotFound();

    $this->getJson('/user/passkeys/options')->assertStatus($enabled ? 401 : 404);
})->with(['on' => true, 'off' => false]);

it('hides the passkeys section on the security page while the module is off', function (bool $enabled): void {
    usePasskeysModule($enabled);
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $response = $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();

    // Visible text only: the Livewire snapshot always holds the property name `canManagePasskeys`.
    $enabled
        ? $response->assertSeeText(__('Manage your passkeys for passwordless sign-in'))
        : $response->assertDontSeeText(__('Manage your passkeys for passwordless sign-in'));
})->with(['on' => true, 'off' => false]);

it('registers the passkeys limiter while the module is on', function (): void {
    expect(RateLimiter::limiter('passkeys'))->not->toBeNull();
})->skip(fn (): bool => ! config('skv.modules.passkeys'), 'booted with the module off: Task 2 step 8 runs that path');
