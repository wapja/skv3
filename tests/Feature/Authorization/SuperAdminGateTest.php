<?php

declare(strict_types=1);

/** covers §4.4.2, §4.6.4, §6.3 */

use App\Authorization\Permission;
use App\Authorization\SuperAdminGate;
use App\Models\User;

it('grants super admins every ability except the always-checked ones', function (): void {
    $superAdmin = User::factory()->superAdmin()->make();

    expect(SuperAdminGate::before($superAdmin, Permission::RolesManage->value))->toBeTrue()
        ->and(SuperAdminGate::before($superAdmin, 'update'))->toBeTrue();

    foreach (SuperAdminGate::ALWAYS_CHECKED as $ability) {
        expect(SuperAdminGate::before($superAdmin, $ability))->toBeNull();
    }
});

it('never decides for other users', function (): void {
    expect(SuperAdminGate::before(User::factory()->make(), Permission::UsersView->value))->toBeNull();
});

it('treats a user without organisation but without the flag as a normal user (W8)', function (): void {
    $user = User::factory()->make(['organisation_id' => null]);

    expect(SuperAdminGate::before($user, Permission::RolesManage->value))->toBeNull();
});

it('is registered with the gate', function (): void {
    $superAdmin = asSuperAdmin();

    expect($superAdmin->can(Permission::SystemHealth))->toBeTrue();
});
