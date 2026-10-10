<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withSession;

/** Signs in a member with 2FA on their own host; `password.confirm` lets the security page through. */
function memberOnSecurityPage(): User
{
    $user = User::factory()->withTwoFactor()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    actingAs($user);
    withSession(['auth.password_confirmed_at' => time()]);

    return $user;
}

/** Lets the password confirmation of the current session expire. */
function expirePasswordConfirmation(): void
{
    withSession(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout') - 1]);
}

/**
 * The snapshot of $component as the security page renders it for the signed-in user.
 *
 * @return array<string, mixed>
 */
function securityPageSnapshot(string $component): array
{
    preg_match_all('/wire:snapshot="([^"]*)"/', (string) get(route('security.edit'))->assertOk()->getContent(), $matches);

    foreach ($matches[1] as $encoded) {
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode(htmlspecialchars_decode($encoded, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);

        if (($snapshot['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new LogicException("The security page renders no [{$component}] component.");
}

/**
 * Sends a Livewire update that calls $method on the component, as a forged request would.
 *
 * @param  array<string, mixed>  $snapshot
 * @return TestResponse<Response>
 */
function callOnSecurityPage(array $snapshot, string $method): TestResponse
{
    Livewire::flushState(); // a new request cycle, as in production

    return postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'updates' => [],
        'calls' => [['method' => $method, 'params' => [], 'metadata' => []]],
    ]]], ['X-Livewire' => '1']);
}

it('refuses to disable 2FA through a Livewire update once the password confirmation expired', function (): void {
    $user = memberOnSecurityPage();
    $snapshot = securityPageSnapshot('pages::settings.security');
    expirePasswordConfirmation();

    callOnSecurityPage($snapshot, 'disable')
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));
    expect($user->fresh()?->hasEnabledTwoFactorAuthentication())->toBeTrue();

    withSession(['auth.password_confirmed_at' => time()]);
    callOnSecurityPage($snapshot, 'disable')->assertOk();
    expect($user->fresh()?->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('refuses to regenerate recovery codes through a Livewire update once the password confirmation expired', function (): void {
    $user = memberOnSecurityPage();
    $snapshot = securityPageSnapshot('pages::settings.two-factor.recovery-codes');
    expirePasswordConfirmation();

    callOnSecurityPage($snapshot, 'regenerateRecoveryCodes')
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));
    expect(json_decode(decrypt((string) $user->fresh()?->two_factor_recovery_codes), true))->toBe(['recovery-code-1']);

    withSession(['auth.password_confirmed_at' => time()]);
    callOnSecurityPage($snapshot, 'regenerateRecoveryCodes')->assertOk();
    expect(json_decode(decrypt((string) $user->fresh()?->two_factor_recovery_codes), true))->not->toBe(['recovery-code-1']);
});

it('sends a stale password confirmation to the confirmation screen before any security action runs', function (string $component, string $method): void {
    $user = memberOnSecurityPage();
    $before = $user->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
    expirePasswordConfirmation();

    Livewire::test($component, ['requiresConfirmation' => true])
        ->call($method)
        ->assertRedirect(route('password.confirm'))
        ->assertNoFileDownloaded();

    expect($user->fresh()?->only(array_keys($before)))->toEqual($before);
})->with([
    'disable 2FA' => ['pages::settings.security', 'disable'],
    'delete a passkey' => ['pages::settings.security', 'deletePasskey'],
    'start 2FA setup' => ['pages::settings.two-factor-setup-modal', 'startTwoFactorSetup'],
    'confirm 2FA setup' => ['pages::settings.two-factor-setup-modal', 'confirmTwoFactor'],
    'regenerate recovery codes' => ['pages::settings.two-factor.recovery-codes', 'regenerateRecoveryCodes'],
    'download recovery codes' => ['pages::settings.two-factor.recovery-codes', 'downloadRecoveryCodes'],
]);

it('never shows the TOTP secret to a session whose password confirmation expired', function (): void {
    memberOnSecurityPage();
    expirePasswordConfirmation();

    Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->call('startTwoFactorSetup')
        ->assertSet('manualSetupKey', '')
        ->assertSet('qrCodeSvg', '');
});

it('returns to the security page once the password is confirmed again', function (): void {
    memberOnSecurityPage();
    $snapshot = securityPageSnapshot('pages::settings.security');
    expirePasswordConfirmation();

    callOnSecurityPage($snapshot, 'disable');

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect(route('security.edit'));
});
