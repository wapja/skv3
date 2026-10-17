<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
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

it('refuses to download stored codes it cannot read instead of failing with a server error (G2-07)', function (Closure $stored): void {
    $user = memberWithRecoveryCodes();
    $component = Livewire::withoutLazyLoading()->test('pages::settings.two-factor.recovery-codes');

    // The column changed after this page loaded: APP_KEY rotated without APP_PREVIOUS_KEYS, or a damaged value.
    $user->forceFill(['two_factor_recovery_codes' => $stored()])->save();

    $component->call('downloadRecoveryCodes')
        ->assertHasErrors(['recoveryCodes' => __('Failed to load recovery codes')])
        ->assertNoFileDownloaded();
})->with([
    'encrypted with another key' => [fn (): string => new Encrypter(random_bytes(32), (string) config('app.cipher'))->encrypt(json_encode(['recovery-code-1']))],
    'not a list of codes' => [fn (): string => encrypt('not json')],
    'a JSON object' => [fn (): string => encrypt(json_encode(['first' => 'recovery-code-1']))],
    'a list of numbers' => [fn (): string => encrypt(json_encode([1, 2]))],
    'a list with a number in it' => [fn (): string => encrypt(json_encode(['recovery-code-1', 2]))],
    'an empty list' => [fn (): string => encrypt(json_encode([]))],
]);

it('renders the security page with an error when the stored codes cannot be read (G2-07)', function (Closure $stored): void {
    $user = memberWithRecoveryCodes();
    $user->forceFill(['two_factor_recovery_codes' => $stored()])->save();

    $this->get(route('security.edit'))->assertOk()->assertSee(__('Failed to load recovery codes'));
})->with([
    'not a list of codes' => [fn (): string => encrypt('not json')],
    // Fortify serialises before it encrypts; unserialize() warns about this value, which is a 500 without the catch.
    'encrypted without serialising' => [fn (): string => Crypt::encryptString((string) json_encode(['recovery-code-1']))],
    'a list of numbers' => [fn (): string => encrypt(json_encode([1, 2]))],
]);

it('offers to regenerate codes it cannot read (G2-07)', function (): void {
    $user = memberWithRecoveryCodes();
    $user->forceFill(['two_factor_recovery_codes' => encrypt('not json')])->save();

    $html = Livewire::withoutLazyLoading()->test('pages::settings.two-factor.recovery-codes')
        ->assertHasErrors('recoveryCodes')
        ->html();

    // The trigger and the confirmation modal's button; the modal alone always renders one.
    expect(substr_count($html, __('Regenerate codes')))->toBe(2);
});
