<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withSession;

function memberWithRecoveryCodes(): User
{
    $user = User::factory()->withTwoFactor()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    actingAs($user);
    withSession(['auth.password_confirmed_at' => time()]);

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

it('downloads the stored recovery codes, not the page\'s snapshot (FU3-4)', function (): void {
    $user = memberWithRecoveryCodes();
    $component = Livewire::withoutLazyLoading()->test('pages::settings.two-factor.recovery-codes');

    // Codes regenerated elsewhere (another tab) after this page loaded. Regenerate on the signed-in instance:
    // Livewire::test() calls skip the session middleware, so Auth::user() stays this object; the database and
    // Auth::user() change, the component's snapshot does not.
    app(GenerateNewRecoveryCodes::class)($user);
    $stored = $user->recoveryCodes();
    expect($stored)->not->toBe($component->get('recoveryCodes'));

    $component->call('downloadRecoveryCodes')
        ->assertFileDownloaded('recovery-codes.txt', implode(PHP_EOL, $stored).PHP_EOL);
});

it('downloads nothing once another tab has turned two-factor off (FU3-4)', function (): void {
    $user = memberWithRecoveryCodes();
    $component = Livewire::withoutLazyLoading()->test('pages::settings.two-factor.recovery-codes');

    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    $component->call('downloadRecoveryCodes')
        ->assertHasErrors('recoveryCodes')
        ->assertNoFileDownloaded();
});

it('waits for the clipboard before saying the codes were copied (FU3-5)', function (): void {
    memberWithRecoveryCodes();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('navigator.clipboard.writeText', false)
        ->assertSee('.then(', false);
});
