<?php

declare(strict_types=1);

/** covers §4.4, §4.6 */

use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Authorization\UserAbilities;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;

/** @return array{organisation: Organisation, admin: User, member: User, outsider: User, superAdmin: User, otherSuperAdmin: User} */
function userPolicyCast(): array
{
    $organisation = Organisation::factory()->create();
    $outsider = User::factory()->create();
    $superAdmin = asSuperAdmin();
    $otherSuperAdmin = User::factory()->superAdmin()->create();
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);

    return ['organisation' => $organisation, 'admin' => $admin, 'member' => $member, 'outsider' => $outsider, 'superAdmin' => $superAdmin, 'otherSuperAdmin' => $otherSuperAdmin];
}

/** A user of the organisation holding one custom team role with these permissions. */
$memberWithRole = function (Organisation $organisation, string $role, Permission ...$permissions): User {
    $teamRole = RoleModel::query()->create(['name' => $role, 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $teamRole->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole($teamRole));
    $user->unsetRelation('roles');

    return $user;
};

$adminOf = function (Organisation $organisation): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');

    return $user;
};

/**
 * The five user-management abilities, through the policy or through the batch evaluation an index
 * page uses (RL-26). Later abilities of the page rows (impersonate) are left out on purpose.
 *
 * @return array<string, bool>
 */
$abilitiesOf = function (string $path, User $actor, User $target): array {
    if ($path === 'page') {
        return Arr::only(
            app(UserAbilities::class)->forPage($actor, collect([$target]))[$target->id],
            ['view', 'update', 'updateStatus', 'delete', 'restore'],
        );
    }

    $gate = Gate::forUser($actor);

    return [
        'view' => $gate->allows('view', $target),
        'update' => $gate->allows('update', $target),
        'updateStatus' => $gate->allows('updateStatus', $target),
        'delete' => $gate->allows('delete', $target),
        'restore' => $gate->allows('restore', $target),
    ];
};

it('lets an organisation admin manage members of their own organisation', function (): void {
    ['admin' => $admin, 'member' => $member] = userPolicyCast();
    $gate = Gate::forUser($admin);

    expect($gate->allows('viewAny', User::class))->toBeTrue()
        ->and($gate->allows('view', $member))->toBeTrue()
        ->and($gate->allows('update', $member))->toBeTrue()
        ->and($gate->allows('updateStatus', $member))->toBeTrue()
        ->and($gate->allows('updateRoles', $member))->toBeTrue()
        ->and($gate->allows('delete', $member))->toBeTrue();
});

it('keeps an organisation admin out of other organisations and away from super admins', function (): void {
    ['admin' => $admin, 'outsider' => $outsider, 'superAdmin' => $superAdmin] = userPolicyCast();
    $gate = Gate::forUser($admin);

    foreach (['view', 'update', 'updateStatus', 'updateRoles', 'delete'] as $ability) {
        expect($gate->allows($ability, $outsider))->toBeFalse("{$ability} outsider")
            ->and($gate->allows($ability, $superAdmin))->toBeFalse("{$ability} super admin");
    }

    expect($gate->allows('updateSuperAdmin', $outsider))->toBeFalse();
});

it('stops users from deleting or disabling themselves, super admins included (W2, W12)', function (): void {
    ['admin' => $admin, 'superAdmin' => $superAdmin] = userPolicyCast();

    expect(Gate::forUser($admin)->allows('delete', $admin))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateStatus', $admin))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('delete', $superAdmin))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('updateStatus', $superAdmin))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('updateSuperAdmin', $superAdmin))->toBeFalse();
});

it('keeps organisation admins away from a super admin of their own organisation', function (): void {
    $organisation = Organisation::factory()->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $gate = Gate::forUser(asOrgAdmin($organisation));

    foreach (['view', 'update', 'updateStatus', 'updateRoles', 'delete'] as $ability) {
        expect($gate->allows($ability, $promoted->fresh()))->toBeFalse($ability);
    }
});

it('lets super admins manage everyone else', function (): void {
    ['superAdmin' => $superAdmin, 'otherSuperAdmin' => $other, 'outsider' => $outsider] = userPolicyCast();
    $gate = Gate::forUser($superAdmin);

    expect($gate->allows('update', $outsider))->toBeTrue()
        ->and($gate->allows('delete', $outsider))->toBeTrue()
        ->and($gate->allows('delete', $other))->toBeTrue()
        ->and($gate->allows('updateRoles', $outsider))->toBeTrue()
        ->and($gate->allows('updateSuperAdmin', $outsider))->toBeTrue();
});

it('never assigns organisation roles to super admins', function (): void {
    ['superAdmin' => $superAdmin, 'otherSuperAdmin' => $other] = userPolicyCast();

    expect(Gate::forUser($superAdmin)->allows('updateRoles', $other))->toBeFalse();
});

it('gives members without permissions nothing', function (): void {
    ['organisation' => $organisation, 'admin' => $admin] = userPolicyCast();
    $member = asMember($organisation);
    $gate = Gate::forUser($member);

    expect($gate->allows('viewAny', User::class))->toBeFalse();

    foreach (['view', 'update', 'updateStatus', 'updateRoles', 'delete', 'updateSuperAdmin'] as $ability) {
        expect($gate->allows($ability, $admin))->toBeFalse($ability);
    }
});

it('lets a custom role act only on users whose permissions it holds (RL-26)', function (string $path) use ($memberWithRole, $adminOf, $abilitiesOf): void {
    $organisation = Organisation::factory()->create();
    $helpdesk = $memberWithRole($organisation, 'helpdesk', Permission::UsersView, Permission::UsersUpdate, Permission::UsersDelete);
    $admin = $adminOf($organisation);
    $viewer = $memberWithRole($organisation, 'viewer', Permission::UsersView);
    $plain = User::factory()->for($organisation)->create();
    onTenant($organisation);

    expect($abilitiesOf($path, $helpdesk, $admin))->toBe(['view' => true, 'update' => false, 'updateStatus' => false, 'delete' => false, 'restore' => false])
        ->and($abilitiesOf($path, $helpdesk, $viewer))->toBe(['view' => true, 'update' => true, 'updateStatus' => true, 'delete' => true, 'restore' => true])
        ->and($abilitiesOf($path, $helpdesk, $plain))->toBe(['view' => true, 'update' => true, 'updateStatus' => true, 'delete' => true, 'restore' => true]);
})->with(['policy', 'page']);

it('lets organisation admins manage each other (equal permission sets)', function (string $path) use ($adminOf, $abilitiesOf): void {
    $organisation = Organisation::factory()->create();
    $colleague = $adminOf($organisation);
    $admin = asOrgAdmin($organisation);

    expect($abilitiesOf($path, $admin, $colleague))->toBe(['view' => true, 'update' => true, 'updateStatus' => true, 'delete' => true, 'restore' => true])
        ->and($abilitiesOf($path, $admin, $admin))->toBe(['view' => true, 'update' => true, 'updateStatus' => false, 'delete' => false, 'restore' => true]);
})->with(['policy', 'page']);

it('counts a direct permission of the target (RK-I6)', function (string $path) use ($memberWithRole, $abilitiesOf): void {
    $organisation = Organisation::factory()->create();
    $helpdesk = $memberWithRole($organisation, 'helpdesk', Permission::UsersView, Permission::UsersUpdate);
    $target = User::factory()->for($organisation)->create();
    onTenant($organisation);

    expect($abilitiesOf($path, $helpdesk, $target)['update'])->toBeTrue();

    withPermissionsTeam($organisation->id, fn (): User => $target->givePermissionTo(Permission::RolesAssign->value));
    $target->unsetRelation('permissions');

    expect($abilitiesOf($path, $helpdesk, $target)['update'])->toBeFalse();
})->with(['policy', 'page']);

it('lets only users.delete holders of the same organisation restore', function (string $path) use ($memberWithRole, $abilitiesOf): void {
    $organisation = Organisation::factory()->create();
    $deleted = User::factory()->for($organisation)->create();
    $deleted->delete();
    $editor = $memberWithRole($organisation, 'editor', Permission::UsersView, Permission::UsersUpdate);
    $otherAdmin = asOrgAdmin(Organisation::factory()->create());
    $superAdmin = asSuperAdmin();
    $admin = asOrgAdmin($organisation);

    expect($abilitiesOf($path, $admin, $deleted)['restore'])->toBeTrue()
        ->and($abilitiesOf($path, $editor, $deleted)['restore'])->toBeFalse()
        ->and($abilitiesOf($path, $otherAdmin, $deleted)['restore'])->toBeFalse()
        ->and($abilitiesOf($path, $superAdmin, $deleted)['restore'])->toBeTrue();
})->with(['policy', 'page']);

it('handles actors without an organisation in every user and organisation ability (FU2-11)', function (): void {
    $organisation = Organisation::factory()->create();
    $actor = User::factory()->for($organisation)->create();
    $target = User::factory()->for($organisation)->create();
    $actor->forceFill(['organisation_id' => null])->saveQuietly();
    $target->forceFill(['organisation_id' => null])->saveQuietly();
    onAdminHost();
    PermissionsTeam::run(null, fn (): User => $actor->givePermissionTo(Permission::values()));
    $actor->unsetRelation('permissions');
    $gate = Gate::forUser($actor);

    // The actor holds every permission, so each refusal below comes from the missing organisation.
    expect($gate->allows('viewAny', User::class))->toBeTrue()
        ->and($gate->allows('viewAny', Organisation::class))->toBeTrue()
        ->and(Arr::only(app(UserAbilities::class)->forPage($actor, collect([$target]))[$target->id], ['view', 'update', 'updateStatus', 'delete', 'restore']))
        ->toBe(['view' => false, 'update' => false, 'updateStatus' => false, 'delete' => false, 'restore' => false]);

    foreach (['view', 'update', 'updateStatus', 'delete', 'restore', 'updateRoles', 'updateSuperAdmin'] as $ability) {
        expect($gate->allows($ability, $target))->toBeFalse($ability);
    }

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect($gate->allows($ability, $organisation))->toBeFalse("organisation {$ability}");
    }
});
