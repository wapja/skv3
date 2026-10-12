<?php

declare(strict_types=1);

/** covers §4.0, §6.2 */

use App\Models\User;
use App\Tenancy\Auth\UnscopedPasskey;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkeys;

it('is on by default', function (): void {
    expect(config('tenancy.strict'))->toBeTrue();
});

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
