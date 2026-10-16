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

it('limits the account-security routes to ten requests a minute per user (CD-3)', function (string $method, string $route): void {
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
})->with([
    'enable 2FA' => ['POST', 'two-factor.enable'],
    'confirm 2FA' => ['POST', 'two-factor.confirm'],
    'disable 2FA' => ['DELETE', 'two-factor.disable'],
    'regenerate recovery codes' => ['POST', 'two-factor.regenerate-recovery-codes'],
    'delete a passkey' => ['DELETE', 'passkey.destroy'],
]);
