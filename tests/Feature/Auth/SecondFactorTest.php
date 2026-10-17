<?php

declare(strict_types=1);

/** covers §6.6, §6.8 */

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
