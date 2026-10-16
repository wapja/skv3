<?php

declare(strict_types=1);

/** covers §6.8 */

use App\Models\Organisation;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Listeners\SetPasskeyOrigin;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
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
        ? $response->assertSeeText(__('Manage your passkeys for passwordless sign-in'))->assertSeeText(__('Remove passkey'))
        : $response->assertDontSeeText(__('Manage your passkeys for passwordless sign-in'))->assertDontSeeText(__('Remove passkey'));
})->with(['on' => true, 'off' => false]);

it('registers the passkeys limiter while the module is on', function (): void {
    expect(RateLimiter::limiter('passkeys'))->not->toBeNull();
})->skip(fn (): bool => ! config('skv.modules.passkeys'), 'booted with the module off: Task 2 step 8 runs that path');

it('gates the passkeys module at boot from MODULE_PASSKEYS', function (bool $enabled): void {
    // The real boot, without usePasskeysModule(): MODULE_PASSKEYS is set in every place env() reads, the
    // application is booted again, and the variable is put back once config has been loaded.
    $saved = [$_ENV['MODULE_PASSKEYS'] ?? null, $_SERVER['MODULE_PASSKEYS'] ?? null, getenv('MODULE_PASSKEYS')];
    $value = $enabled ? 'true' : 'false';
    $_ENV['MODULE_PASSKEYS'] = $_SERVER['MODULE_PASSKEYS'] = $value;
    putenv('MODULE_PASSKEYS='.$value);

    try {
        $this->refreshApplication();
    } finally {
        unset($_ENV['MODULE_PASSKEYS'], $_SERVER['MODULE_PASSKEYS']);
        putenv('MODULE_PASSKEYS');

        if ($saved[0] !== null) {
            $_ENV['MODULE_PASSKEYS'] = $saved[0];
        }

        if ($saved[1] !== null) {
            $_SERVER['MODULE_PASSKEYS'] = $saved[1];
        }

        if ($saved[2] !== false) {
            putenv('MODULE_PASSKEYS='.$saved[2]);
        }
    }

    $passkeyOriginListeners = fn (string $event): array => array_values(array_filter(
        Arr::wrap(Event::getRawListeners()[$event] ?? []),
        fn (mixed $listener): bool => $listener === SetPasskeyOrigin::class,
    ));

    expect(config('skv.modules.passkeys'))->toBe($enabled)
        ->and(Features::canManagePasskeys())->toBe($enabled)
        ->and(Route::has('passkey.login'))->toBe($enabled)
        ->and(Route::has('passkey.login-options'))->toBe($enabled)
        ->and(Route::has('passkey.confirm'))->toBe($enabled)
        ->and(Route::has('passkey.store'))->toBe($enabled)
        ->and(Route::has('well-known.passkeys'))->toBe($enabled)
        ->and(RateLimiter::limiter('passkeys') !== null)->toBe($enabled)
        ->and($passkeyOriginListeners(TenantResolved::class))->toHaveCount($enabled ? 1 : 0)
        ->and($passkeyOriginListeners(TenantForgotten::class))->toHaveCount($enabled ? 1 : 0);

    if (! $enabled) {
        $this->postJson('/passkeys/login')->assertNotFound();
    }
})->with(['on' => true, 'off' => false]);

it('refuses the security page\'s passkey actions while the module is off, whatever an update calls (G2-01)', function (string $method): void {
    usePasskeysModule(false);
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));
    $passkey = $member->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');

    callPageAction($snapshot, $method, $method === 'confirmDelete' ? [$passkey->id] : [])->assertNotFound();

    expect($passkey->fresh())->not->toBeNull();
})->with(['loadPasskeys', 'confirmDelete', 'deletePasskey']);

it('keeps the security page\'s passkey actions while the module is on (G2-01)', function (): void {
    usePasskeysModule(true);
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));
    $passkey = $member->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');

    $response = callPageAction($snapshot, 'confirmDelete', [$passkey->id])->assertOk();

    /** @var array{data: array<string, mixed>} $updated */
    $updated = json_decode((string) $response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($updated['data']['deletingPasskeyName'])->toBe('Laptop');
});
