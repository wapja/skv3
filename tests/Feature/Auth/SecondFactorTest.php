<?php

declare(strict_types=1);

/** covers §6.6, §6.8 */

use App\Authorization\SetSuperAdmin;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\SecondFactor;

it('counts a confirmed TOTP secret, and a passkey only while the passkeys module is on (§6.6, §6.8)', function (string $factor, bool $passkeys, bool $satisfied): void {
    usePasskeysModule($passkeys);
    $user = User::factory()->for(Organisation::factory())->create();

    match ($factor) {
        'a confirmed TOTP secret' => $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save(),
        // Fortify's confirm option is on: a secret whose setup was never confirmed is no second factor.
        'an unconfirmed TOTP secret' => $user->forceFill(['two_factor_secret' => encrypt('secret')])->save(),
        'a passkey' => $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]),
        default => null, // 'nothing'
    };

    expect(SecondFactor::satisfiedBy($user))->toBe($satisfied);
})->with([
    'nothing' => ['nothing', true, false],
    'an unconfirmed TOTP secret' => ['an unconfirmed TOTP secret', true, false],
    'a confirmed TOTP secret' => ['a confirmed TOTP secret', true, true],
    'a confirmed TOTP secret, passkeys off' => ['a confirmed TOTP secret', false, true],
    'a passkey' => ['a passkey', true, true],
    'a passkey, passkeys off' => ['a passkey', false, false],
]);

it('requires a second factor of a member only when their own organisation says so, on every host (§6.6, CD-4)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $acmeMember = User::factory()->for($acme)->create();
    $betaMember = User::factory()->for($beta)->create();

    foreach ([fn (): Organisation => onTenant($acme), fn (): Organisation => onTenant($beta), onApex(...), onAdminHost(...)] as $host) {
        $host();

        expect(SecondFactor::requiredFor($acmeMember))->toBeTrue()
            ->and(SecondFactor::requiredFor($betaMember))->toBeFalse();
    }
});

it('requires a second factor of super admins only when the super-admin switch is on, whatever their organisation (CD-4)', function (bool $configured): void {
    config(['skv.second_factor.super_admins' => $configured]);
    $acme = Organisation::factory()->create(['require_second_factor' => true]);
    $platformAdmin = User::factory()->superAdmin()->create();
    // SetSuperAdmin keeps the organisation_id of a promoted member; their organisation's rule no longer applies.
    $promoted = tap(User::factory()->for($acme)->create(), fn (User $user) => app(SetSuperAdmin::class)->handle($user, true));

    expect(SecondFactor::requiredFor($platformAdmin))->toBe($configured)
        ->and(SecondFactor::requiredFor($promoted))->toBe($configured);
})->with(['on' => true, 'off' => false]);
