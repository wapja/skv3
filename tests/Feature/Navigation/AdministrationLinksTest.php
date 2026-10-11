<?php

declare(strict_types=1);

/** covers §6.5 (interim navigation, RL-11) */

use App\Authorization\Permission;
use App\Models\Organisation;
use App\Models\User;
use Spatie\Permission\Models\Role as RoleModel;

it('shows the administration links that match the actor\'s permissions', function (string $actor, array $visible): void {
    $organisation = Organisation::factory()->create();

    match ($actor) {
        'member' => asMember($organisation),
        'organisation admin' => asOrgAdmin($organisation),
        default => asSuperAdmin(),
    };

    if ($actor === 'super admin') {
        onAdminHost();
    }

    $response = $this->get(route('dashboard'))->assertOk();

    // Later tasks append their own link tests to this file.
    foreach (['users.index'] as $link) {
        in_array($link, $visible, true)
            ? $response->assertSee('href="'.route($link).'"', false)
            : $response->assertDontSee('href="'.route($link).'"', false);
    }

    $visible === []
        ? $response->assertDontSee(__('Administration'))
        : $response->assertSee(__('Administration'));
})->with([
    'member' => ['member', []],
    'organisation admin' => ['organisation admin', ['users.index']],
    'super admin' => ['super admin', ['users.index']],
]);

it('shows the pending invitations link to users who may view users', function (string $actor, bool $visible): void {
    $organisation = Organisation::factory()->create();

    match ($actor) {
        'member' => asMember($organisation),
        'organisation admin' => asOrgAdmin($organisation),
        default => asSuperAdmin(),
    };

    if ($actor === 'super admin') {
        onAdminHost();
    }

    $response = $this->get(route('dashboard'))->assertOk();

    if ($visible) {
        $response->assertSee('data-test="nav-pending-invitations"', false);
    } else {
        $response->assertDontSee('data-test="nav-pending-invitations"', false);
    }
})->with([
    'member' => ['member', false],
    'organisation admin' => ['organisation admin', true],
    'super admin' => ['super admin', true],
]);

it('shows the invite button only to users who may create invitations (OR-M7)', function (): void {
    $organisation = Organisation::factory()->create();
    asOrgAdmin($organisation);

    $this->get(route('users.index'))->assertOk()->assertSee('data-test="invite-user-button"', false);

    $creator = User::factory()->for($organisation)->create();
    $role = RoleModel::query()->create(['name' => 'creator', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo([Permission::UsersView->value, Permission::UsersCreate->value]);
    withPermissionsTeam($organisation->id, fn () => $creator->assignRole($role));
    $this->actingAs($creator);

    $this->get(route('users.index'))->assertOk()->assertDontSee('data-test="invite-user-button"', false);
});

it('shows the organisations link to organisation admins and super admins, not to members', function (): void {
    $organisation = Organisation::factory()->create();

    asMember($organisation);
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('organisations.index'), escape: false);

    asOrgAdmin($organisation);
    $this->get(route('dashboard'))->assertOk()->assertSee(route('organisations.index'), escape: false);

    asSuperAdmin();
    onAdminHost();
    $this->get(route('dashboard'))->assertOk()->assertSee(route('organisations.index'), escape: false);
});
