<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Actions\Users\UpdateUserRoles;
use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

function teamRole(Organisation $organisation, string $name): RoleModel
{
    return RoleModel::query()->create(['name' => $name, 'guard_name' => 'web', 'team_id' => $organisation->id]);
}

function systemRoleId(Role $role): int
{
    return (int) RoleModel::query()->whereNull('team_id')->where('name', $role->value)->value('id');
}

/** @return list<string> */
function roleNamesOf(User $user, Organisation $organisation): array
{
    $names = array_map(
        fn (mixed $name): string => (string) $name,
        withPermissionsTeam($organisation->id, fn (): array => $user->fresh()?->getRoleNames()->all() ?? []),
    );
    sort($names);

    return $names;
}

it('assigns team roles and the organisation admin template in the target team', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = teamRole($organisation, 'editor');
    $admin = asOrgAdmin($organisation);

    app(UpdateUserRoles::class)->handle($admin, $member, [(int) $editor->id, systemRoleId(Role::OrganisationAdmin)]);

    expect(roleNamesOf($member, $organisation))->toBe(['editor', 'organisation_admin'])
        ->and(DB::table('model_has_roles')->where('model_id', $member->id)->pluck('team_id')->unique()->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$organisation->id]);
});

it('rejects the super admin badge and roles of other organisations (W1)', function (int|string $role): void {
    $organisation = Organisation::factory()->create();
    $foreign = teamRole(Organisation::factory()->create(), 'foreign');
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);
    $roleId = match ($role) {
        'super admin badge' => systemRoleId(Role::SuperAdmin),
        'foreign team role' => (int) $foreign->id,
        default => (int) $role,
    };

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $member, [$roleId]))
        ->toThrow(ValidationException::class);
    expect(roleNamesOf($member, $organisation))->toBe([]);
})->with(['super admin badge', 'foreign team role', 999999]);

it('refuses granting roles with permissions the actor lacks (W1)', function (): void {
    $organisation = Organisation::factory()->create();
    $assigner = teamRole($organisation, 'assigner');
    $assigner->givePermissionTo(Permission::RolesAssign->value);
    $actor = asMember($organisation);
    withPermissionsTeam($organisation->id, fn (): User => $actor->assignRole($assigner));
    $actor->unsetRelation('roles');

    expect(fn () => app(UpdateUserRoles::class)->handle($actor, $actor, [(int) $assigner->id, systemRoleId(Role::OrganisationAdmin)]))
        ->toThrow(ValidationException::class);
    expect(roleNamesOf($actor, $organisation))->toBe(['assigner']);
});

it('refuses targets outside the actor\'s organisation and super admin targets', function (): void {
    $organisation = Organisation::factory()->create();
    $outsider = User::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $outsider, []))->toThrow(AuthorizationException::class);
    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $superAdmin, []))->toThrow(AuthorizationException::class);
});

it('refuses actors without roles.assign', function (): void {
    $organisation = Organisation::factory()->create();
    $target = User::factory()->for($organisation)->create();
    $member = asMember($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($member, $target, []))->toThrow(AuthorizationException::class);
});

it('refuses removing your own organisation admin role (W12)', function (): void {
    $organisation = Organisation::factory()->create();
    $other = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $other->assignRole(Role::OrganisationAdmin->value));
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $admin, []))->toThrow(ValidationException::class);
    expect(roleNamesOf($admin, $organisation))->toBe(['organisation_admin']);
});

it('refuses demoting the last active organisation admin (W12)', function (): void {
    $organisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create();
    $disabledAdmin = User::factory()->for($organisation)->disabled()->create();
    withPermissionsTeam($organisation->id, function () use ($onlyAdmin, $disabledAdmin): void {
        $onlyAdmin->assignRole(Role::OrganisationAdmin->value);
        $disabledAdmin->assignRole(Role::OrganisationAdmin->value);
    });
    $superAdmin = asSuperAdmin();
    onTenant($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($superAdmin, $onlyAdmin, []))->toThrow(ValidationException::class);
});

it('allows demoting an organisation admin when another active one remains', function (): void {
    $organisation = Organisation::factory()->create();
    $leaving = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $leaving->assignRole(Role::OrganisationAdmin->value));
    $admin = asOrgAdmin($organisation);

    app(UpdateUserRoles::class)->handle($admin, $leaving, []);

    expect(roleNamesOf($leaving, $organisation))->toBe([]);
});

it('assigns roles in the target team when a super admin acts from the admin host', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = teamRole($organisation, 'editor');
    $superAdmin = asSuperAdmin();
    onAdminHost();
    expect(PermissionsTeam::current())->toBeNull();

    app(UpdateUserRoles::class)->handle($superAdmin, $member, [(int) $editor->id, systemRoleId(Role::OrganisationAdmin)]);

    expect(DB::table('model_has_roles')->where('model_id', $member->id)->pluck('team_id')->unique()->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$organisation->id])
        ->and(roleNamesOf($member, $organisation))->toBe(['editor', 'organisation_admin'])
        ->and(PermissionsTeam::current())->toBeNull();
});

it('restores the permissions team after refusing to demote the last admin from the admin host', function (): void {
    $organisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $onlyAdmin->assignRole(Role::OrganisationAdmin->value));
    $superAdmin = asSuperAdmin();
    onAdminHost();
    expect(PermissionsTeam::current())->toBeNull();

    expect(fn () => app(UpdateUserRoles::class)->handle($superAdmin, $onlyAdmin, []))->toThrow(ValidationException::class);

    expect(PermissionsTeam::current())->toBeNull()
        ->and(roleNamesOf($onlyAdmin, $organisation))->toBe(['organisation_admin']);
});

it('treats only the template role, by id, as organisation admin (FU2-4)', function (): void {
    // Fails on main: a team role named organisation_admin counted as the template, so removing it
    // from the last "admin" was refused although the organisation has no real organisation admin.
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $legacy = teamRole($organisation, 'organisation_admin');
    withPermissionsTeam($organisation->id, fn (): User => $member->assignRole($legacy));
    $member->unsetRelation('roles');
    $superAdmin = asSuperAdmin();
    onTenant($organisation);

    app(UpdateUserRoles::class)->handle($superAdmin, $member, []);

    expect(roleNamesOf($member, $organisation))->toBe([]);
});

it('refuses team roles named like a system role in any case or spacing (FU2-4, W6)', function (string $name): void {
    // Fails on main: these team roles were assignable like any other.
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    // The admin gets the template first: assignRole('organisation_admin') looks roles up by name.
    $admin = asOrgAdmin($organisation);
    $lookalike = teamRole($organisation, $name);

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $member, [(int) $lookalike->id]))
        ->toThrow(ValidationException::class, __('One or more roles are not available for this organisation.'));
    expect(roleNamesOf($member, $organisation))->toBe([]);
})->with(['organisation_admin', 'Organisation_Admin', ' super admin ', 'organisation-admin', 'SUPER_ADMIN']);

it('ignores roles of another guard (FU2-4)', function (): void {
    // Fails on main: the role was resolved and syncRoles() threw Spatie's GuardDoesNotMatch instead of a validation error.
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $apiRole = RoleModel::query()->create(['name' => 'api-editor', 'guard_name' => 'api', 'team_id' => $organisation->id]);
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $member, [(int) $apiRole->id]))
        ->toThrow(ValidationException::class, __('One or more roles are not available for this organisation.'));
    expect(roleNamesOf($member, $organisation))->toBe([]);
});
