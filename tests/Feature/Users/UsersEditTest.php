<?php

declare(strict_types=1);

/** covers §4.4, §6.2, §6.3 */

use App\Actions\Users\UpdateSuperAdmin;
use App\Actions\Users\UpdateUserRoles;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EmailChangedByAdministrator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

use function Pest\Laravel\actingAs;

/** A member of $organisation holding one team role with exactly these permissions. */
$memberWith = function (Organisation $organisation, Permission ...$permissions): User {
    $role = RoleModel::query()->create(['name' => 'custom-'.Str::lower(Str::random(6)), 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole($role));
    $user->unsetRelation('roles');

    return $user;
};

/** The id of the global organisation_admin template role. */
$templateId = fn (): int => (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

/** @return list<int> */
$roleIdsOf = fn (User $user): array => DB::table('model_has_roles')
    ->where('model_id', $user->id)
    ->where('team_id', $user->organisation_id)
    ->orderBy('role_id')
    ->pluck('role_id')
    ->map(fn (mixed $id): int => (int) $id)
    ->all();

it('shows a member read-only to a viewer without users.update', function () use ($memberWith): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mila Member']);
    actingAs($memberWith($organisation, Permission::UsersView));
    onTenant($organisation);

    $this->get(route('users.edit', ['user' => $member->id]))
        ->assertOk()
        ->assertSee('Mila Member')
        ->assertDontSee('wire:submit="saveAccount"', false)
        ->assertDontSee('wire:submit="saveRoles"', false);

    Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('name', 'Renamed')
        ->call('saveAccount')
        ->assertForbidden();

    expect($member->fresh()?->name)->toBe('Mila Member');
});

it('saves name and email through UpdateUser', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mila Member', 'email' => 'mila@acme.test']);
    asOrgAdmin($organisation);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::users.edit', ['user' => $member->id])
        ->assertSet('name', 'Mila Member')
        ->set('name', 'Mila de Vries')
        ->set('email', 'Mila.deVries@Acme.test')
        ->call('saveAccount')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertDispatched('toast-show');

    expect($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila de Vries', 'email' => 'mila.devries@acme.test']);
    Notification::assertSentOnDemand(EmailChangedByAdministrator::class);
});

it('asks for a fresh password confirmation before changing another user\'s address and comes back to this page (RL-18)', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mila Member', 'email' => 'mila@acme.test']);
    asOrgAdmin($organisation);
    $page = route('users.edit', ['user' => $member->id]);
    $snapshot = pageSnapshot($page, 'pages::users.edit');

    callPageAction($snapshot, 'saveAccount', updates: ['name' => 'Mila de Vries', 'email' => 'new@acme.test', 'status' => UserStatus::Disabled->value])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));

    expect($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila Member', 'email' => 'mila@acme.test'])
        ->and($member->fresh()?->status)->toBe(UserStatus::Active);

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($page);

    // The unsaved form comes back after the confirmation, with a notice (draftRestored); saving it applies all three fields.
    $restored = pageSnapshot($page, 'pages::users.edit');

    expect($restored['data']['name'] ?? null)->toBe('Mila de Vries')
        ->and($restored['data']['email'] ?? null)->toBe('new@acme.test')
        ->and($restored['data']['status'] ?? null)->toBe(UserStatus::Disabled->value)
        ->and($restored['data']['draftRestored'] ?? null)->toBeTrue();

    callPageAction($restored, 'saveAccount')->assertOk()->assertJsonMissingPath('components.0.effects.redirect');

    expect($member->fresh()?->only(['name', 'email']))->toBe(['name' => 'Mila de Vries', 'email' => 'new@acme.test'])
        ->and($member->fresh()?->status)->toBe(UserStatus::Disabled);
});

it('shows your own address read-only with a link to the profile page and refuses a forged change (E1)', function (): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('users.edit', ['user' => $admin->id]))
        ->assertOk()
        ->assertSee(__('Change your own address on your profile page.'))
        ->assertSee('data-test="own-profile-link"', false)
        ->assertDontSee('wire:model="email"', false);

    Livewire::test('pages::users.edit', ['user' => $admin->id])
        ->set('email', 'evil@attacker.test')
        ->call('saveAccount')
        ->assertHasNoErrors();

    expect($admin->fresh()?->email)->toBe($admin->email);
});

it('shows a pending user\'s address and status read-only', function (): void {
    $organisation = Organisation::factory()->create();
    $pending = User::factory()->for($organisation)->pending()->create(['email' => 'piet@acme.test']);
    asOrgAdmin($organisation);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('users.edit', ['user' => $pending->id]))
        ->assertOk()
        ->assertSee('piet@acme.test')
        ->assertSee(__('Cancel the invitation and invite the new address.'))
        ->assertDontSee('wire:model="email"', false)
        ->assertDontSee('wire:model="status"', false);

    Livewire::test('pages::users.edit', ['user' => $pending->id])
        ->set('email', 'other@acme.test')
        ->set('status', UserStatus::Active->value)
        ->call('saveAccount')
        ->assertHasNoErrors();

    expect($pending->fresh()?->only(['email', 'status']))->toEqual(['email' => 'piet@acme.test', 'status' => UserStatus::PendingActivation]);
});

it('changes the status and never offers pending activation (W4)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asOrgAdmin($organisation);

    $this->get(route('users.edit', ['user' => $member->id]))
        ->assertOk()
        ->assertSee('value="'.UserStatus::Disabled->value.'"', false)
        ->assertDontSee('value="'.UserStatus::PendingActivation->value.'"', false);

    Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('status', UserStatus::PendingActivation->value)
        ->call('saveAccount')
        ->assertHasErrors(['status' => 'in']);

    expect($member->fresh()?->status)->toBe(UserStatus::Active);

    Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('status', UserStatus::Disabled->value)
        ->call('saveAccount')
        ->assertHasNoErrors();

    expect($member->fresh()?->status)->toBe(UserStatus::Disabled);
});

it('writes roles only through the roles form and rejects the super admin badge, another team\'s role and a role the actor lacks (W1)', function () use ($roleIdsOf): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $platform = RoleModel::query()->create(['name' => 'platform', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $platform->givePermissionTo(Permission::RolesManage->value);
    $foreign = RoleModel::query()->create(['name' => 'foreign', 'guard_name' => 'web', 'team_id' => Organisation::factory()->create()->id]);
    $badgeId = (int) RoleModel::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->value('id');
    asOrgAdmin($organisation);

    Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('roles', [(string) $editor->id])
        ->call('saveRoles')
        ->assertHasNoErrors()
        ->assertSet('roles', [(string) $editor->id]);

    expect($roleIdsOf($member))->toBe([(int) $editor->id]);

    foreach ([$badgeId, (int) $foreign->id, (int) $platform->id] as $refused) {
        Livewire::test('pages::users.edit', ['user' => $member->id])
            ->set('roles', [(string) $refused])
            ->call('saveRoles')
            ->assertHasErrors(['roles']);

        expect($roleIdsOf($member))->toBe([(int) $editor->id]);
    }
});

it('exposes no public method that writes roles besides saveRoles (W1)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asOrgAdmin($organisation);
    $class = new ReflectionClass(Livewire::test('pages::users.edit', ['user' => $member->id])->instance());

    $declared = collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName())
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->sort()
        ->values()
        ->all();

    $roleWriters = collect($class->getMethods())
        ->filter(fn (ReflectionMethod $method): bool => collect($method->getParameters())->contains(
            fn (ReflectionParameter $parameter): bool => $parameter->getType() instanceof ReflectionNamedType
                && $parameter->getType()->getName() === UpdateUserRoles::class,
        ))
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values()
        ->all();

    expect($declared)->toBe(['abilities', 'mount', 'roleOptions', 'saveAccount', 'saveRoles', 'saveSuperAdmin', 'target'])
        ->and($roleWriters)->toBe(['saveRoles']);
});

it('refuses a replayed snapshot from an actor who may view and assign roles but lacks the granted role\'s permissions (W1, RK-I9)', function () use ($memberWith, $templateId, $roleIdsOf): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asOrgAdmin($organisation);
    $snapshot = pageSnapshot(route('users.edit', ['user' => $member->id]), 'pages::users.edit');
    actingAs($memberWith($organisation, Permission::UsersView, Permission::RolesAssign));

    $response = callPageAction($snapshot, 'saveRoles', updates: ['roles' => [(string) $templateId()]])->assertOk();

    /** @var array{memo: array{errors: array<string, mixed>}} $returned */
    $returned = json_decode((string) $response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($returned['memo']['errors'])->toHaveKey('roles')
        ->and($roleIdsOf($member))->toBe([]);
});

it('refuses a replayed roles save from an actor with users.view but no roles.assign (W1)', function () use ($memberWith, $templateId, $roleIdsOf): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asOrgAdmin($organisation);
    $snapshot = pageSnapshot(route('users.edit', ['user' => $member->id]), 'pages::users.edit');
    actingAs($memberWith($organisation, Permission::UsersView));

    // The route gate (users.view) lets the update through; the page and the action refuse it.
    callPageAction($snapshot, 'saveRoles', updates: ['roles' => [(string) $templateId()]])->assertForbidden();

    expect($roleIdsOf($member))->toBe([]);
});

it('hides status and role controls on your own account and refuses forged changes (W12)', function () use ($templateId, $roleIdsOf): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);

    $this->get(route('users.edit', ['user' => $admin->id]))
        ->assertOk()
        ->assertDontSee('wire:model="status"', false)
        ->assertDontSee('wire:submit="saveRoles"', false);

    Livewire::test('pages::users.edit', ['user' => $admin->id])
        ->set('status', UserStatus::Disabled->value)
        ->call('saveAccount')
        ->assertForbidden();

    Livewire::test('pages::users.edit', ['user' => $admin->id])
        ->set('roles', [])
        ->call('saveRoles')
        ->assertForbidden();

    expect($admin->fresh()?->status)->toBe(UserStatus::Active)
        ->and($roleIdsOf($admin))->toBe([$templateId()]);
});

it('keeps a pending user\'s roles and super admin flag as invited and refuses forged changes (RK-B3)', function () use ($templateId, $roleIdsOf): void {
    $organisation = Organisation::factory()->create();
    $viewer = RoleModel::query()->create(['name' => 'viewer', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $pending = User::factory()->for($organisation)->pending()->create();
    withPermissionsTeam($organisation->id, fn (): User => $pending->assignRole($viewer));
    asOrgAdmin($organisation);

    $this->get(route('users.edit', ['user' => $pending->id]))
        ->assertOk()
        ->assertSee(__('Cancel the invitation and invite again with the new roles.'))
        ->assertDontSee('wire:submit="saveRoles"', false);

    Livewire::test('pages::users.edit', ['user' => $pending->id])
        ->set('roles', [(string) $templateId()])
        ->call('saveRoles')
        ->assertHasErrors(['roles']);

    expect($roleIdsOf($pending))->toBe([(int) $viewer->id]);

    // No fresh confirmation in the session: the refusal comes before the password round trip (assertNoRedirect).
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $this->get(route('users.edit', ['user' => $pending->id]))
        ->assertOk()
        ->assertDontSee('wire:submit="saveSuperAdmin"', false);

    Livewire::test('pages::users.edit', ['user' => $pending->id])
        ->set('isSuperAdmin', true)
        ->call('saveSuperAdmin')
        ->assertHasErrors(['is_super_admin'])
        ->assertNoRedirect();

    expect(fn () => app(UpdateSuperAdmin::class)->handle($superAdmin, $pending, true))->toThrow(ValidationException::class);

    expect($pending->fresh()?->isSuperAdmin())->toBeFalse()
        ->and($roleIdsOf($pending))->toBe([(int) $viewer->id]);
});

it('keeps an organisation admin\'s roles when a super admin renames them on the admin host, with the checkboxes ticked (X1)', function () use ($templateId, $roleIdsOf): void {
    $organisation = Organisation::factory()->create();
    $orgAdmin = User::factory()->for($organisation)->create(['name' => 'Olga Admin']);
    withPermissionsTeam($organisation->id, fn (): User => $orgAdmin->assignRole(Role::OrganisationAdmin->value));
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::users.edit', ['user' => $orgAdmin->id])
        ->assertSet('roles', [(string) $templateId()])
        ->set('name', 'Olga de Admin')
        ->call('saveAccount')
        ->assertHasNoErrors();

    expect($orgAdmin->fresh()?->name)->toBe('Olga de Admin')
        ->and($roleIdsOf($orgAdmin))->toBe([$templateId()]);
});

it('asks for a fresh password confirmation before switching super admin and comes back to this page (RL-18)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asSuperAdmin();
    onAdminHost();
    $page = route('users.edit', ['user' => $member->id]);
    $snapshot = pageSnapshot($page, 'pages::users.edit');

    callPageAction($snapshot, 'saveSuperAdmin', updates: ['isSuperAdmin' => true])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));

    expect($member->fresh()?->isSuperAdmin())->toBeFalse();

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($page);
    callPageAction($snapshot, 'saveSuperAdmin', updates: ['isSuperAdmin' => true])->assertOk();

    expect($member->fresh()?->isSuperAdmin())->toBeTrue();
});

it('switches super admin only through UpdateSuperAdmin (RK-M8)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $orgAdmin = asOrgAdmin($organisation);

    expect(fn () => app(UpdateSuperAdmin::class)->handle($orgAdmin, $member, true))->toThrow(AuthorizationException::class);

    Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('isSuperAdmin', true)
        ->call('saveSuperAdmin')
        ->assertForbidden();

    $superAdmin = asSuperAdmin();

    expect(fn () => app(UpdateSuperAdmin::class)->handle($superAdmin, $superAdmin, false))->toThrow(AuthorizationException::class);

    app(UpdateSuperAdmin::class)->handle($superAdmin, $member, true);

    expect($member->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(filesMatching(kitSourceFiles('resources/views', 'routes'), '/\bSetSuperAdmin\b/'))->toBe([]);
});

it('answers 404 for another organisation\'s user on a tenant host and finds any user for a super admin on the admin host (USR-20)', function (): void {
    $acme = Organisation::factory()->create();
    $stranger = User::factory()->for(Organisation::factory())->create();
    $promoted = User::factory()->for($acme)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $operator = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($operator, true);
    asOrgAdmin($acme);

    $this->get(route('users.edit', ['user' => $stranger->id]))->assertNotFound();
    $this->get(route('users.edit', ['user' => 999999]))->assertNotFound();
    $this->get(route('users.edit', ['user' => $promoted->id]))->assertForbidden();

    asSuperAdmin();
    onAdminHost();

    $this->get(route('users.edit', ['user' => $stranger->id]))->assertOk()->assertSee($stranger->name);
    $this->get(route('users.edit', ['user' => $operator->id]))->assertOk()->assertSee($operator->name);
});

it('links each row the actor may view to its edit page', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asOrgAdmin($organisation);

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSee('href="'.route('users.edit', ['user' => $member->id]).'"', false);
});
