<?php

declare(strict_types=1);

/** covers §4.2.5, §4.2.6, §4.10.2 */

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function (): void {
    usePasskeysModule(true);
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Passkeys');
    $response->assertSee('No passkeys yet');
    $response->assertSee('Two-factor authentication');
    $response->assertSee('Enable 2FA');
});

test('security settings page requires password confirmation when enabled', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('security settings page renders without two factor when feature is disabled', function (): void {
    config(['fortify.features' => []]);

    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('two factor authentication disabled when confirmation abandoned between requests', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.security');

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('password can be updated', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

it('lists the user\'s own passkeys and deletes one after the confirmation (§4.2.5)', function (): void {
    usePasskeysModule(true);
    $user = User::factory()->create();
    $colleague = User::factory()->create(['organisation_id' => $user->organisation_id]);
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $laptop = $user->passkeys()->create(['name' => 'Work laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $colleague->passkeys()->create(['name' => 'Colleague key', 'credential_id' => 'credential-2', 'credential' => []]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk()
        ->assertSee('Work laptop')
        ->assertDontSee('Colleague key')
        ->assertDontSee('No passkeys yet')
        ->assertSeeHtml('wire:click="confirmDelete('.$laptop->id.')"');

    $confirmation = callPageAction(pageSnapshot(route('security.edit'), 'pages::settings.security'), 'confirmDelete', [$laptop->id])->assertOk();
    /** @var array<string, mixed> $snapshot */
    $snapshot = json_decode((string) $confirmation->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    $deleted = callPageAction($snapshot, 'deletePasskey')->assertOk();

    expect($user->passkeys()->count())->toBe(0)
        ->and($colleague->passkeys()->count())->toBe(1)
        ->and((string) $deleted->json('components.0.effects.html'))->toContain('No passkeys yet');
});
