<?php

declare(strict_types=1);

/** covers §4.2, §4.5 */

use App\Authorization\Role;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\withSession;

beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * The validation errors a Livewire update returned.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
$errorsOf = function (TestResponse $response): array {
    /** @var array{memo: array{errors?: array<string, list<string>>}} $snapshot */
    $snapshot = json_decode((string) $response->assertOk()->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    return $snapshot['memo']['errors'] ?? [];
};

$throttled = fn (int $seconds): string => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]);

it('sends at most three email-change links per ten minutes and saves nothing when it refuses (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['name' => 'Ada', 'email' => 'old@example.com']);
    onTenant($organisation);
    $this->actingAs($member);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    foreach (range(1, 3) as $attempt) {
        expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['email' => "new{$attempt}@example.com"])))->toBe([]);
    }

    $this->travel(1)->minute();

    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve', 'email' => 'new4@example.com'])))
        ->toBe(['email' => [$throttled(540)]]);
    Notification::assertSentOnDemandTimes(ConfirmEmailChange::class, 3);
    expect($member->fresh()?->name)->toBe('Ada');

    // Saving the name alone sends nothing, so it is never refused.
    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve'])))->toBe([])
        ->and($member->fresh()?->name)->toBe('Eve');
});

it('gives the email-change slot back when saving the name throws (CD-3)', function (): void {
    // The slot was reserved before the name save and the language, but only the send gave it back on a throw.
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['name' => 'Ada', 'email' => 'old@example.com']);
    onTenant($organisation);
    $this->actingAs($member);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');
    User::saving(function (User $user): void {
        if ($user->name === 'Boom') {
            throw new RuntimeException('The name save failed.');
        }
    });

    callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Boom', 'email' => 'new@example.com'])->assertServerError();

    expect(RateLimiter::attempts(UserActionLimit::emailChange()->key($member)))->toBe(0);
    Notification::assertNothingSent();
});

it('counts every password change attempt before it checks the current password (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.security');
    $change = fn (string $current): TestResponse => callPageAction($snapshot, 'updatePassword', updates: [
        'current_password' => $current,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    foreach (range(1, 5) as $attempt) {
        expect($errorsOf($change('wrong-password')))->toHaveKey('current_password');
    }

    expect($errorsOf($change('password')))->toBe(['current_password' => [$throttled(60)]])
        ->and(Hash::check('password', (string) $member->fresh()?->password))->toBeTrue();
});

it('counts every two-factor confirmation before it checks the code (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.two-factor-setup-modal');
    // The page clears an unconfirmed secret when it mounts, so the setup starts after the snapshot, as the modal's would.
    app(EnableTwoFactorAuthentication::class)($member);
    $secret = (string) Fortify::currentEncrypter()->decrypt((string) $member->fresh()?->two_factor_secret);
    $validCode = (string) app(Google2FA::class)->getCurrentOtp($secret);
    $wrongCode = $validCode === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        expect($errorsOf(callPageAction($snapshot, 'confirmTwoFactor', updates: ['code' => $wrongCode])))->toHaveKey('code');
    }

    expect($errorsOf(callPageAction($snapshot, 'confirmTwoFactor', updates: ['code' => $validCode])))->toBe(['code' => [$throttled(60)]])
        ->and($member->fresh()?->two_factor_confirmed_at)->toBeNull();
});

it('counts sent invitations per inviter, gives a refused one back and refuses the one past ten a minute (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $templateId = (string) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    $snapshot = pageSnapshot(route('users.invite'), 'pages::users.invite');
    $invite = fn (string $email): TestResponse => callPageAction($snapshot, 'invite', updates: ['email' => $email, 'roles' => [$templateId]]);
    UserActionLimit::invite()->hit($admin, cost: 9);

    // InviteUser refuses an invitation without a role; the component gives the reserved slot back.
    expect($errorsOf(callPageAction($snapshot, 'invite', updates: ['email' => 'no-role@example.com', 'roles' => []])))->toHaveKey('roles')
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(9);

    expect($errorsOf($invite('tenth@example.com')))->toBe([])
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(10)
        ->and($errorsOf($invite('eleventh@example.com')))->toBe(['email' => [$throttled(60)]])
        ->and(User::query()->where('email', 'eleventh@example.com')->exists())->toBeFalse();
});

it('limits reminders from the pending list per actor and shows why (CD-3, G1-01)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $first = inviteMember($organisation, $admin, 'first@example.com');
    $second = inviteMember($organisation, $admin, 'second@example.com');
    $snapshot = pageSnapshot(route('invitations.pending'), 'pages::invitations.pending');
    UserActionLimit::remind()->hit($admin, cost: 9);

    expect($errorsOf(callPageAction($snapshot, 'remind', [$first->id])))->toBe([]);

    // Livewire keeps only property errors in the snapshot; "invitation" is a message slot, so read the page.
    expect((string) callPageAction($snapshot, 'remind', [$second->id])->assertOk()->json('components.0.effects.html'))->toContain($throttled(60))
        ->and($first->fresh()?->reminder_sent_at)->not->toBeNull()
        ->and($second->fresh()?->reminder_sent_at)->toBeNull();
});

it('limits a resend from the accept page with the pending list\'s limit and shows why (CD-3, G1-01)', function () use ($throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $admin = asOrgAdmin($organisation);
    $this->travel(8)->days();
    $snapshot = pageSnapshot(acceptLinkFor($invitation), 'pages::invitations.accept');
    UserActionLimit::remind()->hit($admin, cost: 10);

    expect((string) callPageAction($snapshot, 'resend')->assertOk()->json('components.0.effects.html'))->toContain($throttled(60))
        ->and(Invitation::query()->findOrFail($invitation->id)->reminder_sent_at)->toBeNull();
});

it('limits the account-security routes to ten requests a minute per user, not per IP (CD-3)', function (string $method, string $route): void {
    usePasskeysModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onTenant($organisation);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $uri = route($route, $route === 'passkey.destroy' ? ['passkey' => 999_999] : []);

    foreach (range(1, 10) as $attempt) {
        expect($this->json($method, $uri)->getStatusCode())->not->toBe(429);
    }

    $this->json($method, $uri)->assertTooManyRequests();

    // Per user: another member behind the same IP still gets through.
    $this->actingAs(User::factory()->for($organisation)->withTwoFactor()->create());
    withSession(['auth.password_confirmed_at' => time()]);

    expect($this->json($method, $uri)->getStatusCode())->not->toBe(429);
})->with([
    'enable 2FA' => ['POST', 'two-factor.enable'],
    'confirm 2FA' => ['POST', 'two-factor.confirm'],
    'disable 2FA' => ['DELETE', 'two-factor.disable'],
    'regenerate recovery codes' => ['POST', 'two-factor.regenerate-recovery-codes'],
    'delete a passkey' => ['DELETE', 'passkey.destroy'],
]);

it('counts every address a profile save looks up, a refused one too, and refuses the lookup past 500 a day (FM-18, CD-2)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    $member = User::factory()->for($organisation)->create(['name' => 'Ada', 'email' => 'old@example.com']);
    onTenant($organisation);
    $this->actingAs($member);
    $key = UserActionLimit::addressLookup()->key($member);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');
    UserActionLimit::addressLookup()->hit($member, cost: 498);

    // An address held elsewhere is refused, and its lookup still counts: attempt() never gives it back.
    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['email' => 'taken@example.com'])))
        ->toBe(['email' => [__('This address can\'t be used.')]])
        ->and(RateLimiter::attempts($key))->toBe(499);

    // The stored address, in other letters, is no lookup.
    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve', 'email' => 'OLD@example.com'])))->toBe([])
        ->and($member->fresh()?->name)->toBe('Eve')
        ->and(RateLimiter::attempts($key))->toBe(499);

    expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Eve', 'email' => 'new@example.com'])))->toBe([])
        ->and(RateLimiter::attempts($key))->toBe(500);
    Notification::assertSentOnDemandTimes(ConfirmEmailChange::class, 1);

    $this->travel(1)->hour();

    // Past the limit no address is looked up: a taken and a free one get the same answer, and nothing is saved.
    foreach (['taken@example.com', 'free@example.com'] as $address) {
        expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'Zed', 'email' => $address])))
            ->toBe(['email' => [$throttled(82800)]]);
    }

    expect($member->fresh()?->name)->toBe('Eve')
        ->and(UserActionLimit::addressLookup())->toEqual(new UserActionLimit('address-lookup', 500, 86400));
    Notification::assertSentOnDemandTimes(ConfirmEmailChange::class, 1);
});

it('counts the address the invite page looks up, also when InviteUser refuses it, before the invitation limit (FM-18, CD-2)', function () use ($errorsOf, $throttled): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    $admin = asOrgAdmin($organisation);
    $templateId = (string) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    $snapshot = pageSnapshot(route('users.invite'), 'pages::users.invite');
    $invite = fn (string $email): TestResponse => callPageAction($snapshot, 'invite', updates: ['email' => $email, 'roles' => [$templateId]]);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    UserActionLimit::addressLookup()->hit($admin, cost: 499);

    // InviteUser refuses the address and the invite slot is given back; the lookup is not.
    expect($errorsOf($invite('taken@example.com')))->toBe(['email' => [__('This address can\'t be used.')]])
        ->and(RateLimiter::attempts($lookups))->toBe(500)
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(0);

    expect($errorsOf($invite('new@example.com')))->toBe(['email' => [$throttled(86400)]])
        ->and(RateLimiter::attempts(UserActionLimit::invite()->key($admin)))->toBe(0)
        ->and(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('counts the address an administrator types on users.edit before the confirmation and the address-change limit (FM-18, CD-2)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    $member = User::factory()->for($organisation)->create(['name' => 'Mila', 'email' => 'mila@acme.test']);
    $admin = asOrgAdmin($organisation);
    $lookups = UserActionLimit::addressLookup()->key($admin);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('users.edit', ['user' => $member->id]), 'pages::users.edit');
    UserActionLimit::addressLookup()->hit($admin, cost: 499);

    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'taken@example.com'])))->toBe(['email' => [__('This address can\'t be used.')]])
        ->and(RateLimiter::attempts($lookups))->toBe(500);

    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['name' => 'Mila de Vries', 'email' => 'mila.new@acme.test'])))->toBe(['email' => [$throttled(86400)]])
        ->and($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila', 'email' => 'mila@acme.test'])
        ->and(RateLimiter::attempts(UserActionLimit::addressChange()->key($admin)))->toBe(0);

    // A save that keeps the address, here in other letters, looks nothing up.
    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['name' => 'Mila de Vries', 'email' => 'MILA@acme.test'])))->toBe([])
        ->and($member->fresh()?->name)->toBe('Mila de Vries')
        ->and(RateLimiter::attempts($lookups))->toBe(501);
});

it('refuses a new address on the profile during an impersonation before any lookup, and charges nobody (FM-18, CD-2)', function () use ($errorsOf): void {
    useImpersonationModule(true);
    Notification::fake();
    $organisation = onTenant(Organisation::factory()->create(['slug' => 'acme']));
    User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    $member = User::factory()->for($organisation)->create(['email' => 'mila@acme.test']);
    startImpersonatingOverHttp($admin, $member);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    // A taken and a free address get the same answer: an impersonation never changes the address, so neither is
    // looked up, and impersonating member after member adds no lookups to anyone's day.
    foreach (['taken@example.com', 'free@example.com'] as $address) {
        expect($errorsOf(callPageAction($snapshot, 'updateProfileInformation', updates: ['email' => $address])))
            ->toBe(['email' => [__('The address can\'t be changed during an impersonation.')]]);
    }

    expect(RateLimiter::attempts(UserActionLimit::addressLookup()->key($member)))->toBe(0)
        ->and(RateLimiter::attempts(UserActionLimit::addressLookup()->key($admin)))->toBe(0);
    Notification::assertNothingSent();
});

it('charges the impersonator, not the impersonated administrator, for the lookups of the invite, edit and bulk pages (FM-18, CD-2)', function () use ($errorsOf): void {
    useImpersonationModule(true);
    Notification::fake();
    $organisation = onTenant(Organisation::factory()->create(['slug' => 'acme']));
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    $member = User::factory()->for($organisation)->create(['email' => 'mila@acme.test']);
    $templateId = (string) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    startImpersonatingOverHttp($superAdmin, $admin);
    withSession(['auth.password_confirmed_at' => time()]);

    expect($errorsOf(callPageAction(pageSnapshot(route('users.invite'), 'pages::users.invite'), 'invite', updates: ['email' => 'new@example.com', 'roles' => [$templateId]])))->toBe([])
        ->and($errorsOf(callPageAction(pageSnapshot(route('users.edit', ['user' => $member->id]), 'pages::users.edit'), 'saveAccount', updates: ['email' => 'mila.new@acme.test'])))->toBe([])
        ->and($errorsOf(callPageAction(pageSnapshot(route('invitations.bulk'), 'pages::invitations.bulk'), 'check', updates: ['addresses' => 'ada@example.com, bob@example.com', 'roles' => [$templateId]])))->toBe([])
        ->and(RateLimiter::attempts(UserActionLimit::addressLookup()->key($superAdmin)))->toBe(4)
        ->and(RateLimiter::attempts(UserActionLimit::addressLookup()->key($admin)))->toBe(0);
});
