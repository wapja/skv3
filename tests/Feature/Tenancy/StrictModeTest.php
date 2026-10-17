<?php

declare(strict_types=1);

/** covers §4.0, §6.2 */

use App\Models\User;
use App\Tenancy\Auth\UnscopedPasskey;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkeys;

it('is on by default, and TENANCY_STRICT=false turns it off (A5, FU2-12)', function (?string $value, bool $strict): void {
    // phpunit.xml forces TENANCY_STRICT=true, so config('tenancy.strict') says nothing about the shipped default: the
    // variable is cleared in every place env() reads (and set for the off row), the config file is read again, and the
    // variable is put back afterwards.
    $saved = [$_ENV['TENANCY_STRICT'] ?? null, $_SERVER['TENANCY_STRICT'] ?? null, getenv('TENANCY_STRICT')];
    unset($_ENV['TENANCY_STRICT'], $_SERVER['TENANCY_STRICT']);
    putenv('TENANCY_STRICT');

    if ($value !== null) {
        $_ENV['TENANCY_STRICT'] = $_SERVER['TENANCY_STRICT'] = $value;
        putenv('TENANCY_STRICT='.$value);
    }

    try {
        /** @var array{strict: mixed} $config */
        $config = require config_path('tenancy.php');
    } finally {
        unset($_ENV['TENANCY_STRICT'], $_SERVER['TENANCY_STRICT']);
        putenv('TENANCY_STRICT');

        if ($saved[0] !== null) {
            $_ENV['TENANCY_STRICT'] = $saved[0];
        }

        if ($saved[1] !== null) {
            $_SERVER['TENANCY_STRICT'] = $saved[1];
        }

        if ($saved[2] !== false) {
            putenv('TENANCY_STRICT='.$saved[2]);
        }
    }

    expect($config['strict'])->toBe($strict)
        ->and(config('tenancy.strict'))->toBeTrue();
})->with([
    'TENANCY_STRICT unset' => [null, true],
    'TENANCY_STRICT=false' => ['false', false],
]);

it('refuses tenant queries on a central host outside a bypass', function (): void {
    Route::middleware('web')->get('/_count', fn (): int => User::query()->count());
    $this->withoutExceptionHandling();

    expect(fn () => $this->get(Hosts::url(Hosts::apex(), '/_count')))->toThrow(TenantNotResolvedException::class);
});

it('lets a super admin pass the two-factor challenge on a central host', function (): void {
    $superAdmin = User::factory()->superAdmin()->withTwoFactor()->create();
    onAdminHost();

    $this->post(route('login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->get(route('two-factor.login'))->assertOk();
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($superAdmin);
});

it('loads a passkey user without the tenant scope on a central host', function (): void {
    usePasskeysModule(true);
    $superAdmin = User::factory()->superAdmin()->create();
    $passkey = new UnscopedPasskey;
    $passkey->setAttribute('user_id', $superAdmin->id);
    onAdminHost();

    expect(Passkeys::passkeyModel())->toBe(UnscopedPasskey::class)
        ->and($passkey->user()->first()?->is($superAdmin))->toBeTrue();
});
