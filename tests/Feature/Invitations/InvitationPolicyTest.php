<?php

declare(strict_types=1);

/** covers §4.5, §4.6, §6.3 */

use App\Actions\Invitations\InviteUser;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;

$teamRoleWith = function (Organisation $organisation, string $name, Permission ...$permissions): RoleModel {
    $role = RoleModel::query()->create(['name' => $name, 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));

    return $role;
};

$holderOf = function (Organisation $organisation, RoleModel|string $role): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole($role));
    $user->unsetRelation('roles');

    return $user;
};

it('lets a super admin and an inviter who could grant the roles see the link, and nobody else (RK-B3)', function () use ($teamRoleWith, $holderOf): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $superAdmin = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);
    $betaAdmin = $holderOf($beta, Role::OrganisationAdmin->value);
    onTenant($acme);
    $adminInvitation = inviteMember($acme, $superAdmin);
    $acmeAdmin = $holderOf($acme, Role::OrganisationAdmin->value);
    $helpdesk = $holderOf($acme, $teamRoleWith($acme, 'helpdesk', Permission::UsersView, Permission::UsersInviteResend, Permission::RolesAssign));
    $resender = $holderOf($acme, $teamRoleWith($acme, 'resender', Permission::UsersView, Permission::UsersInviteResend));
    $viewerRole = $teamRoleWith($acme, 'viewer', Permission::UsersView);
    $viewerInvitation = app(InviteUser::class)->handle($superAdmin, $acme, 'viewer@example.com', [(int) $viewerRole->id], Locale::English);

    expect(Gate::forUser($superAdmin)->allows('viewLink', $adminInvitation))->toBeTrue()
        ->and(Gate::forUser($acmeAdmin)->allows('viewLink', $adminInvitation))->toBeTrue()
        ->and(Gate::forUser($helpdesk)->allows('viewLink', $adminInvitation))->toBeFalse()
        ->and(Gate::forUser($helpdesk)->allows('viewLink', $viewerInvitation))->toBeTrue()
        ->and(Gate::forUser($resender)->allows('remind', $adminInvitation))->toBeTrue()
        ->and(Gate::forUser($resender)->allows('viewLink', $adminInvitation))->toBeFalse()
        ->and(Gate::forUser($resender)->allows('viewLink', $viewerInvitation))->toBeFalse()
        ->and(Gate::forUser($betaAdmin)->allows('viewLink', $adminInvitation))->toBeFalse();
});

it('ties remind to users.invite.resend and cancel to users.delete (INV-8)', function () use ($teamRoleWith, $holderOf): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);
    onTenant($acme);
    $viewerRole = $teamRoleWith($acme, 'viewer', Permission::UsersView);
    $invitation = app(InviteUser::class)->handle($superAdmin, $acme, 'viewer@example.com', [(int) $viewerRole->id], Locale::English);
    $resender = $holderOf($acme, $teamRoleWith($acme, 'resender', Permission::UsersView, Permission::UsersInviteResend));
    $deleter = $holderOf($acme, $teamRoleWith($acme, 'deleter', Permission::UsersView, Permission::UsersDelete));
    $viewer = $holderOf($acme, $viewerRole);

    expect(Gate::forUser($resender)->allows('remind', $invitation))->toBeTrue()
        ->and(Gate::forUser($resender)->allows('cancel', $invitation))->toBeFalse()
        ->and(Gate::forUser($deleter)->allows('cancel', $invitation))->toBeTrue()
        ->and(Gate::forUser($deleter)->allows('remind', $invitation))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('remind', $invitation))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('cancel', $invitation))->toBeFalse();
});

it('refuses organisation members every invitation ability on a pending super admin (§6.3)', function () use ($holderOf): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);
    onTenant($acme);
    $invitation = inviteMember($acme, $superAdmin);
    $acmeAdmin = $holderOf($acme, Role::OrganisationAdmin->value);

    // A direct write stands in for any path that ever promotes a pending user: the policy must not rely on that path being closed.
    $invitation->user->forceFill(['is_super_admin' => true])->save();

    foreach (['remind', 'cancel', 'viewLink'] as $ability) {
        expect(Gate::forUser($acmeAdmin)->allows($ability, $invitation))->toBeFalse()
            ->and(Gate::forUser($superAdmin)->allows($ability, $invitation))->toBeTrue();
    }
});

it('requires roles.assign to create (OR-M7)', function () use ($teamRoleWith, $holderOf): void {
    $acme = onTenant(Organisation::factory()->create(['slug' => 'acme']));
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $creatorOnly = $holderOf($acme, $teamRoleWith($acme, 'creator', Permission::UsersCreate));
    $inviter = $holderOf($acme, $teamRoleWith($acme, 'inviter', Permission::UsersCreate, Permission::RolesAssign));
    $superAdmin = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);

    expect(Gate::forUser($creatorOnly)->allows('create', [Invitation::class, $acme]))->toBeFalse()
        ->and(Gate::forUser($inviter)->allows('create', [Invitation::class, $acme]))->toBeTrue()
        ->and(Gate::forUser($inviter)->allows('create', [Invitation::class, $beta]))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('create', [Invitation::class, $beta]))->toBeTrue();
});
