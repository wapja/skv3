<?php

declare(strict_types=1);

/** covers §4.3, §4.4, §4.5 */

use App\Authorization\Role;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EmailChangedByAdministrator;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
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

/** The users.edit page of $target as the signed-in administrator sees it, with a fresh password confirmation (RL-18). */
$editPage = function (User $target): array {
    withSession(['auth.password_confirmed_at' => time()]);

    return pageSnapshot(route('users.edit', ['user' => $target->id]), 'pages::users.edit');
};

/** Creates an organisation through the wizard over HTTP, as the signed-in super admin on the admin host. */
$createOrganisation = fn (string $slug, string $adminEmail): TestResponse => callPageAction(
    pageSnapshot(route('organisations.create'), 'pages::organisations.create'),
    'create',
    updates: ['name' => ucfirst($slug), 'slug' => $slug, 'adminEmail' => $adminEmail],
);

it('changes ten addresses an hour per administrator, refuses the eleventh without saving anything and never counts a kept address (FM-19)', function () use ($errorsOf, $throttled): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['name' => 'Mila', 'email' => 'mila@acme.test']);
    $admin = asOrgAdmin($organisation);
    $key = UserActionLimit::addressChange()->key($admin);
    $page = route('users.edit', ['user' => $member->id]);
    UserActionLimit::addressChange()->hit($admin, cost: 9);

    // Without a fresh confirmation the page parks the form and sends the administrator to confirm first (RL-18):
    // nothing is sent, so nothing counts.
    callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount', updates: ['email' => 'tenth@acme.test'])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));

    expect(RateLimiter::attempts($key))->toBe(9)
        ->and($key)->toBe('address-change:'.$admin->id);

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($page);

    expect($errorsOf(callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount')))->toBe([])
        ->and($member->fresh()?->email)->toBe('tenth@acme.test')
        ->and(RateLimiter::attempts($key))->toBe(10);

    $this->travel(10)->minutes();

    $refused = callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount', updates: [
        'name' => 'Mila de Vries',
        'email' => 'eleventh@acme.test',
        'status' => UserStatus::Disabled->value,
    ]);

    expect($errorsOf($refused))->toBe(['email' => [$throttled(3000)]])
        ->and($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila', 'email' => 'tenth@acme.test'])
        ->and($member->fresh()?->status)->toBe(UserStatus::Active);
    Notification::assertSentOnDemandTimes(EmailChangedByAdministrator::class, 1);

    // The refusal counted (attempt()). A save that keeps the address, here in other letters, sends nothing: it is
    // never refused and adds nothing.
    $kept = callPageAction(pageSnapshot($page, 'pages::users.edit'), 'saveAccount', updates: [
        'name' => 'Mila de Vries',
        'email' => 'TENTH@acme.test',
        'status' => UserStatus::Disabled->value,
    ]);

    expect($errorsOf($kept))->toBe([])
        ->and($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila de Vries', 'email' => 'tenth@acme.test'])
        ->and($member->fresh()?->status)->toBe(UserStatus::Disabled)
        ->and(RateLimiter::attempts($key))->toBe(11);
});

it('gives the slot back when the address is refused or the save fails (FM-19)', function () use ($errorsOf, $editPage): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create(['name' => 'Olga', 'email' => 'olga@acme.test']);
    withPermissionsTeam($organisation->id, fn (): User => $onlyAdmin->assignRole(Role::OrganisationAdmin->value));
    User::factory()->for($organisation)->create(['email' => 'taken@acme.test']);
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $key = UserActionLimit::addressChange()->key($superAdmin);
    $snapshot = $editPage($onlyAdmin);
    UserActionLimit::addressChange()->hit($superAdmin, cost: 9);

    // An address the validation refuses.
    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'taken@acme.test'])))->toHaveKey('email')
        ->and(RateLimiter::attempts($key))->toBe(9);

    // A valid address whose save fails inside the transaction: UpdateUserStatus refuses to disable the last admin (G4-08).
    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'olga.new@acme.test', 'status' => UserStatus::Disabled->value])))->toHaveKey('status')
        ->and(RateLimiter::attempts($key))->toBe(9)
        ->and($onlyAdmin->fresh()?->email)->toBe('olga@acme.test');

    expect($errorsOf(callPageAction($snapshot, 'saveAccount', updates: ['email' => 'olga.new@acme.test'])))->toBe([])
        ->and(RateLimiter::attempts($key))->toBe(10)
        ->and($onlyAdmin->fresh()?->email)->toBe('olga.new@acme.test');
    Notification::assertSentOnDemandTimes(EmailChangedByAdministrator::class, 1);
});

it('creates ten organisations a minute per super admin, gives a failed invitation\'s slot back and refuses the eleventh on the admin address (FM-19)', function () use ($errorsOf, $throttled, $createOrganisation): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $key = UserActionLimit::invite()->key($superAdmin);
    // The wizard's invitation shares the invite page's counter.
    UserActionLimit::invite()->hit($superAdmin, cost: 9);
    // The address is taken between the page's validation and the invitation (FU3-8): InviteUser refuses it and the
    // organisation rolls back.
    Organisation::created(function (Organisation $organisation): void {
        if ($organisation->slug === 'raced') {
            DB::table('users')->insert([
                'organisation_id' => null,
                'name' => 'Racer',
                'email' => 'first@raced.test',
                'password' => 'x',
                'status' => UserStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    expect($errorsOf($createOrganisation('raced', 'first@raced.test')))->toHaveKey('adminEmail')
        ->and(Organisation::query()->where('slug', 'raced')->exists())->toBeFalse()
        ->and(RateLimiter::attempts($key))->toBe(9);

    expect($errorsOf($createOrganisation('tenth', 'first@tenth.test')))->toBe([])
        ->and(Organisation::query()->where('slug', 'tenth')->exists())->toBeTrue()
        ->and(RateLimiter::attempts($key))->toBe(10);

    $this->travel(20)->seconds();

    expect($errorsOf($createOrganisation('eleventh', 'first@eleventh.test')))->toBe(['adminEmail' => [$throttled(40)]])
        ->and(Organisation::query()->where('slug', 'eleventh')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'first@eleventh.test')->exists())->toBeFalse();
});

it('counts both limits per acting administrator (FM-19)', function () use ($errorsOf, $throttled, $editPage, $createOrganisation): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['email' => 'mila@acme.test']);
    $busyAdmin = asOrgAdmin($organisation);
    UserActionLimit::addressChange()->hit($busyAdmin, cost: 10);

    expect($errorsOf(callPageAction($editPage($member), 'saveAccount', updates: ['email' => 'refused@acme.test'])))->toBe(['email' => [$throttled(3600)]]);

    asOrgAdmin($organisation);

    expect($errorsOf(callPageAction($editPage($member), 'saveAccount', updates: ['email' => 'mila.new@acme.test'])))->toBe([])
        ->and($member->fresh()?->email)->toBe('mila.new@acme.test');

    $busySuperAdmin = asSuperAdmin();
    onAdminHost();
    UserActionLimit::invite()->hit($busySuperAdmin, cost: 10);

    expect($errorsOf($createOrganisation('refused', 'first@refused.test')))->toBe(['adminEmail' => [$throttled(60)]]);

    asSuperAdmin();

    expect($errorsOf($createOrganisation('created', 'first@created.test')))->toBe([])
        ->and(Organisation::query()->where('slug', 'created')->exists())->toBeTrue();
});
