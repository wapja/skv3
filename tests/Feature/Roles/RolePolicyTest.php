<?php

declare(strict_types=1);

/** covers §4.6 */

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;

it('is registered for the Spatie role model', function (): void {
    expect(Gate::getPolicyFor(RoleModel::class))->toBeInstanceOf(RolePolicy::class);
});

it('refuses deleting a system role to super admins too', function (): void {
    $organisation = Organisation::factory()->create();
    $superAdmin = asSuperAdmin();
    $custom = RoleModel::query()->create(['name' => 'Support', 'guard_name' => 'web', 'team_id' => $organisation->id]);

    foreach (Role::cases() as $system) {
        $role = RoleModel::query()->whereNull('team_id')->where('name', $system->value)->firstOrFail();

        // update passes through Gate::before; UpdateRole refuses system roles itself (W6).
        expect(Gate::forUser($superAdmin)->allows('delete', $role))->toBeFalse()
            ->and(Gate::forUser($superAdmin)->allows('update', $role))->toBeTrue();
    }

    expect(Gate::forUser($superAdmin)->allows('delete', $custom))->toBeTrue();
});

it('checks the role\'s team for non-super actors (RK-M9)', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $ownRole = RoleModel::query()->create(['name' => 'Support', 'guard_name' => 'web', 'team_id' => $own->id]);
    $otherRole = RoleModel::query()->create(['name' => 'Support', 'guard_name' => 'web', 'team_id' => $other->id]);
    $manager = User::factory()->for($own)->create();
    $member = User::factory()->for($own)->create();
    withPermissionsTeam($own->id, fn (): User => $manager->givePermissionTo(Permission::RolesManage->value));
    $manager->unsetRelation('permissions');
    onTenant($own);

    expect(Gate::forUser($manager)->allows('create', RoleModel::class))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $ownRole))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $ownRole))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $otherRole))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('delete', $otherRole))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', RoleModel::class))->toBeFalse()
        ->and(Gate::forUser($member)->allows('viewAny', RoleModel::class))->toBeFalse();
});
