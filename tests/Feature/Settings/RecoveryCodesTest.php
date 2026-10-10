<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function memberWithRecoveryCodes(): User
{
    $user = User::factory()->withTwoFactor()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    actingAs($user);

    return $user;
}

it('downloads the recovery codes as a text file', function (): void {
    memberWithRecoveryCodes();

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->call('downloadRecoveryCodes')
        ->assertFileDownloaded('recovery-codes.txt', 'recovery-code-1'.PHP_EOL);
});

it('offers to copy the codes and asks before regenerating them', function (): void {
    memberWithRecoveryCodes();

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->assertSee(__('Copy'))
        ->assertSeeHtml('navigator.clipboard.writeText(')
        ->assertSee(__('Regenerate recovery codes?'));
});

it('regenerates the codes and closes the confirmation modal', function (): void {
    $user = memberWithRecoveryCodes();

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->call('regenerateRecoveryCodes')
        ->assertDispatched('modal-close', name: 'confirm-regenerate-recovery-codes');

    expect(json_decode(decrypt((string) $user->fresh()?->two_factor_recovery_codes), true))->not->toBe(['recovery-code-1']);
});
