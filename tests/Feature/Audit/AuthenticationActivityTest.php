<?php

declare(strict_types=1);

/** covers §4.8, §6.4 */

use App\Actions\Auth\IssueHandoff;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\SoftwareAuthenticator;

use function Pest\Laravel\actingAs;

it('records a sign-in tagged with the host\'s organisation', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $entry = recordedActivity(ActivityEvent::Login)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and($entry->getProperty('remember'))->toBeFalse();
});

it('records a super admin\'s sign-in on the admin host as a central entry', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    onAdminHost();

    $this->post(route('login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $entry = recordedActivity(ActivityEvent::Login)->sole();
    expect([$entry->subject_id, $entry->organisation_id])->toBe([$superAdmin->id, null]);
});

it('tags a promoted super admin\'s sign-in by host: central on the admin host and B on organisation B\'s host (RK-I5)', function (): void {
    $home = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $promoted = User::factory()->for($home)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);

    onAdminHost();
    $this->post(route('login.store'), ['email' => $promoted->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
    Auth::guard('web')->logout();
    onTenant($other);
    $this->post(route('login.store'), ['email' => $promoted->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    expect(recordedActivity(ActivityEvent::Login)->pluck('organisation_id')->all())->toBe([null, $other->id]);
});

it('records a failed sign-in with the typed address and never the password (R-4)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $this->post(route('login.store'), ['email' => Str::upper($member->email), 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect($entry->properties?->keys()->all())->toBe(['email'])
        ->and($entry->getProperty('email'))->toBe(Str::lower($member->email))
        ->and([$entry->causer_id, $entry->organisation_id])->toBe([null, $organisation->id])
        ->and(json_encode(DB::table('activity_log')->where('id', $entry->id)->first(), JSON_THROW_ON_ERROR))->not->toContain('wrong-password');
});

it('keeps no typed value that is not an email address (CV-17)', function (): void {
    $organisation = Organisation::factory()->create();
    onTenant($organisation);

    $this->post(route('login.store'), ['email' => 'Secret-Passw0rd!', 'password' => 'anything'])
        ->assertSessionHasErrors('email');

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect($entry->properties?->all())->toBe(['email' => null, 'invalid_address' => true])
        ->and(json_encode(DB::table('activity_log')->where('id', $entry->id)->first(), JSON_THROW_ON_ERROR))
        ->not->toContain('Secret-Passw0rd!')
        ->not->toContain('anything');
});

it('records a sign-out', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $this->actingAs($member)->post(route('logout'))->assertRedirect();

    $entry = recordedActivity(ActivityEvent::Logout)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, $member->id, $organisation->id]);
});

it('records a password reset request and a completed reset', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    // The link is sent in a deferred callback; it runs when the test request terminates.
    $this->post(route('password.email'), ['email' => $member->email]);

    $token = null;
    Notification::assertSentTo($member, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $member->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    $requested = recordedActivity(ActivityEvent::PasswordResetRequested)->sole();
    $completed = recordedActivity(ActivityEvent::PasswordResetCompleted)->sole();
    expect([$requested->subject_id, $requested->causer_id, $requested->organisation_id])->toBe([$member->id, null, $organisation->id])
        ->and([$completed->subject_id, $completed->causer_id, $completed->organisation_id])->toBe([$member->id, null, $organisation->id])
        ->and(json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR))->not->toContain((string) $token);
});

it('records nothing in another organisation\'s log when a reset is asked for there', function (): void {
    Notification::fake();
    $homeOrganisation = Organisation::factory()->create();
    $otherOrganisation = Organisation::factory()->create();
    $member = User::factory()->for($homeOrganisation)->create();
    onTenant($otherOrganisation);

    // The link still goes to the member (on their own host, R1); B's log must never name them.
    $this->post(route('password.email'), ['email' => $member->email]);

    Notification::assertSentTo($member, ResetPassword::class);
    expect(recordedActivity(ActivityEvent::PasswordResetRequested))->toHaveCount(0);
});

it('records nothing in an organisation\'s log when a super admin\'s reset is asked for or completed on its host (R1)', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    onTenant($organisation);

    // Otherwise the organisation's admins could probe addresses and find the platform's super admins in their log.
    $this->post(route('password.email'), ['email' => $superAdmin->email]);

    $token = null;
    Notification::assertSentTo($superAdmin, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $superAdmin->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    expect(recordedActivity(ActivityEvent::PasswordResetRequested))->toHaveCount(0)
        ->and(recordedActivity(ActivityEvent::PasswordResetCompleted))->toHaveCount(0);
});

it('records enabling and disabling two-factor authentication', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    actingAs($member);

    app(EnableTwoFactorAuthentication::class)($member);
    $secret = (string) Fortify::currentEncrypter()->decrypt((string) $member->two_factor_secret);
    app(ConfirmTwoFactorAuthentication::class)($member, (string) app(Google2FA::class)->getCurrentOtp($secret));
    app(DisableTwoFactorAuthentication::class)($member);

    $enabled = recordedActivity(ActivityEvent::TwoFactorEnabled)->sole();
    $disabled = recordedActivity(ActivityEvent::TwoFactorDisabled)->sole();
    expect([$enabled->subject_id, $enabled->causer_id, $enabled->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and([$disabled->subject_id, $disabled->causer_id, $disabled->organisation_id])->toBe([$member->id, $member->id, $organisation->id]);
});

it('does not record the clean-up of a never confirmed two-factor setup', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    actingAs($member);

    app(EnableTwoFactorAuthentication::class)($member);
    app(DisableTwoFactorAuthentication::class)($member);

    expect(recordedActivity(ActivityEvent::TwoFactorEnabled))->toBeEmpty()
        ->and(recordedActivity(ActivityEvent::TwoFactorDisabled))->toBeEmpty();
});

it('records regenerated recovery codes', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onTenant($organisation);
    actingAs($member);

    app(GenerateNewRecoveryCodes::class)($member);

    $entry = recordedActivity(ActivityEvent::RecoveryCodesRegenerated)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and($entry->properties?->all())->toBe([]);
});

it('records a failed two-factor code and a used recovery code (RL-29)', function (): void {
    $secret = app(Google2FA::class)->generateSecretKey();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);
    onTenant($organisation);

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $validCode = (string) app(Google2FA::class)->getCurrentOtp($secret);
    $this->post(route('two-factor.login.store'), ['code' => $validCode === '000000' ? '111111' : '000000'])
        ->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard'));

    $failed = recordedActivity(ActivityEvent::TwoFactorFailed)->sole();
    $used = recordedActivity(ActivityEvent::RecoveryCodeUsed)->sole();
    // Nobody is signed in yet during the challenge: the challenged user is the subject, there is no causer.
    expect([$failed->subject_id, $failed->causer_id, $failed->organisation_id])->toBe([$member->id, null, $organisation->id])
        ->and([$used->subject_id, $used->causer_id, $used->organisation_id])->toBe([$member->id, null, $organisation->id])
        ->and(json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR))->not->toContain('recovery-code-1');
});

it('records a registered and a deleted passkey', function (): void {
    usePasskeysModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()]);

    /** @var array<string, mixed> $options */
    $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Test key', 'credential' => $authenticator->attest($options, 'http://acme.localhost')])
        ->assertSuccessful();
    app(DeletePasskey::class)($member, $member->passkeys()->sole());

    $registered = recordedActivity(ActivityEvent::PasskeyRegistered)->sole();
    $deleted = recordedActivity(ActivityEvent::PasskeyDeleted)->sole();
    expect([$registered->subject_id, $registered->causer_id, $registered->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and($registered->getProperty('passkey'))->toBe('Test key')
        ->and([$deleted->subject_id, $deleted->organisation_id])->toBe([$member->id, $organisation->id])
        ->and($deleted->getProperty('passkey'))->toBe('Test key');
});

it('records a password change on the security page', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    actingAs($member);

    Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $entry = recordedActivity(ActivityEvent::PasswordChanged)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and(json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR))->not->toContain('new-password');
});

it('records a consumed handoff with its host (AUD-13)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();

    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'), '/settings/profile');
    $this->get($url)->assertRedirect('http://acme.localhost/settings/profile');

    $entry = recordedActivity(ActivityEvent::HandoffConsumed)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$superAdmin->id, $superAdmin->id, $organisation->id])
        ->and($entry->getProperty('host'))->toBe('acme.localhost')
        ->and(json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR))->not->toContain(Str::afterLast($url, '/'));
});
