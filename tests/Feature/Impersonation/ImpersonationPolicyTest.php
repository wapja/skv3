<?php

declare(strict_types=1);

/** covers §4.7, §6.8, §7.2 */

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Authorization\UserAbilities;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;

beforeEach(function (): void {
    useImpersonationModule(true);
});

/**
 * A user of $in promoted to super admin. DEC-26 (10): the promotion keeps organisation_id, so only the
 * "never a super admin" clause of the matrix keeps them out (IMP-4).
 */
$promoted = function (Organisation $in): User {
    $user = User::factory()->for($in)->create();
    app(SetSuperAdmin::class)->handle($user, true);

    return $user->refresh();
};

/** A user of $organisation holding the organisation_admin template role (DEC-21), not signed in. */
$organisationAdmin = function (Organisation $organisation): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');

    return $user;
};

/** A user of $organisation whose only role is a custom team role with exactly $permissions. */
$customRoleHolder = function (Organisation $organisation, Permission ...$permissions): User {
    $role = RoleModel::query()->create([
        'name' => 'role-'.Str::lower(Str::random(8)),
        'guard_name' => 'web',
        'team_id' => $organisation->id,
    ]);
    $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole($role));
    $user->unsetRelation('roles');

    return $user;
};

/** A user of $organisation with one direct permission in that team and no role. */
$directPermissionHolder = function (Organisation $organisation, Permission $permission): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->givePermissionTo($permission->value));
    $user->unsetRelation('permissions');

    return $user;
};

it('decides who may impersonate whom on a tenant host (IMP-4, IMP-5, IMP-13, W3, W6, X6, RK-I6, DEC-28 (20))', function (string $case, bool $allowed) use ($promoted, $organisationAdmin, $customRoleHolder, $directPermissionHolder): void {
    $organisation = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $superAdmin = fn (): User => User::factory()->superAdmin()->create();
    $member = fn (Organisation $in): User => User::factory()->for($in)->create();
    $deletedMember = function (Organisation $in): User {
        $user = User::factory()->for($in)->create();
        $user->delete();

        return $user;
    };
    $self = fn (User $user): array => [$user, $user];

    [$actor, $target] = match ($case) {
        'super admin → member' => [$superAdmin(), $member($organisation)],
        'super admin → organisation admin' => [$superAdmin(), $organisationAdmin($organisation)],
        'super admin → super admin' => [$superAdmin(), $superAdmin()],
        'super admin → promoted super admin of this organisation' => [$superAdmin(), $promoted($organisation)],
        'organisation admin → promoted super admin of this organisation' => [$organisationAdmin($organisation), $promoted($organisation)],
        'super admin → member of another organisation' => [$superAdmin(), $member($other)],
        'organisation admin → member' => [$organisationAdmin($organisation), $member($organisation)],
        'organisation admin → member with two-factor authentication' => [$organisationAdmin($organisation), User::factory()->for($organisation)->withTwoFactor()->create()],
        'organisation admin → another organisation admin' => [$organisationAdmin($organisation), $organisationAdmin($organisation)],
        'organisation admin → member of another organisation' => [$organisationAdmin($organisation), $member($other)],
        'member without users.impersonate → member' => [$member($organisation), $member($organisation)],
        'custom role → user it covers' => [
            $customRoleHolder($organisation, Permission::UsersImpersonate, Permission::UsersView),
            $customRoleHolder($organisation, Permission::UsersView),
        ],
        'custom role → user with more permissions' => [
            $customRoleHolder($organisation, Permission::UsersImpersonate),
            $customRoleHolder($organisation, Permission::UsersImpersonate, Permission::UsersDelete),
        ],
        'custom role → user with a direct permission it lacks' => [
            $customRoleHolder($organisation, Permission::UsersImpersonate),
            $directPermissionHolder($organisation, Permission::UsersView),
        ],
        'organisation admin → pending user' => [$organisationAdmin($organisation), User::factory()->for($organisation)->pending()->create()],
        'super admin → disabled user' => [$superAdmin(), User::factory()->for($organisation)->disabled()->create()],
        'super admin → deleted user' => [$superAdmin(), $deletedMember($organisation)],
        'organisation admin → themselves' => $self($organisationAdmin($organisation)),
        default => throw new LogicException("Unknown case [{$case}]."),
    };

    onTenant($organisation);

    expect(Gate::forUser($actor)->allows('impersonate', $target))->toBe($allowed)
        ->and(app(UserAbilities::class)->mayImpersonate($actor, $target))->toBe($allowed);
})->with([
    ['super admin → member', true],
    ['super admin → organisation admin', true],
    ['super admin → super admin', false],
    ['super admin → promoted super admin of this organisation', false],
    ['organisation admin → promoted super admin of this organisation', false],
    ['super admin → member of another organisation', false],
    ['organisation admin → member', true],
    // DEC-28 (20): a second factor never keeps an administrator from impersonating its holder.
    ['organisation admin → member with two-factor authentication', true],
    ['organisation admin → another organisation admin', false],
    ['organisation admin → member of another organisation', false],
    ['member without users.impersonate → member', false],
    ['custom role → user it covers', true],
    ['custom role → user with more permissions', false],
    ['custom role → user with a direct permission it lacks', false],
    ['organisation admin → pending user', false],
    ['super admin → disabled user', false],
    ['super admin → deleted user', false],
    ['organisation admin → themselves', false],
]);

it('refuses while this session is impersonating, on a central host and with the module off (IMP-3, IMP-9, IMP-11)', function (string $case): void {
    $organisation = Organisation::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $member = User::factory()->for($organisation)->create();

    if ($case === 'central host') {
        onAdminHost();
    } else {
        onTenant($organisation);
    }

    if ($case === 'while impersonating') {
        session()->put(Impersonation::SESSION_KEY, $superAdmin->id);
    }

    if ($case === 'module off') {
        useImpersonationModule(false);
    }

    expect(Gate::forUser($superAdmin)->allows('impersonate', $member))->toBeFalse();
})->with(['while impersonating', 'central host', 'module off']);

it('reports a running impersonation whatever the module flag says (RK-I3)', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    session()->put(Impersonation::SESSION_KEY, $superAdmin->id);
    useImpersonationModule(false);

    expect(Impersonation::enabled())->toBeFalse()
        ->and(Impersonation::isActive())->toBeTrue()
        ->and(Impersonation::impersonatorId())->toBe($superAdmin->id);
});

it('evaluates the matrix for a page of users without lazy-loading violations (RK-I6)', function () use ($promoted, $organisationAdmin, $customRoleHolder, $directPermissionHolder): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $otherAdmin = $organisationAdmin($organisation);
    $disabled = User::factory()->for($organisation)->disabled()->create();
    $directHolder = $directPermissionHolder($organisation, Permission::UsersDelete);
    $helpdesk = $customRoleHolder($organisation, Permission::UsersImpersonate, Permission::UsersView);
    $promotedMember = $promoted($organisation);
    $superAdmin = User::factory()->superAdmin()->create();
    onTenant($organisation);

    // A collection of several models: any lazy-loaded relation would throw in strict mode.
    $users = User::query()->orderBy('id')->get();

    $page = app(UserAbilities::class)->forPage($helpdesk, $users);

    foreach ($users as $user) {
        expect($page[$user->id]['impersonate'])->toBe(Gate::forUser($helpdesk)->allows('impersonate', $user));
    }

    expect($page[$member->id]['impersonate'])->toBeTrue()
        ->and($page[$otherAdmin->id]['impersonate'])->toBeFalse()
        ->and($page[$disabled->id]['impersonate'])->toBeFalse()
        ->and($page[$directHolder->id]['impersonate'])->toBeFalse()
        ->and($page[$helpdesk->id]['impersonate'])->toBeFalse()
        ->and($page[$promotedMember->id]['impersonate'])->toBeFalse();

    // A super-admin actor skips every clause but the target checks: only "never a super admin" refuses here.
    expect(app(UserAbilities::class)->forPage($superAdmin, $users)[$promotedMember->id]['impersonate'])->toBeFalse()
        ->and(app(UserAbilities::class)->forPage($superAdmin, $users)[$member->id]['impersonate'])->toBeTrue();
});
