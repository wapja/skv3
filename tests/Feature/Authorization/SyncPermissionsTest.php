<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Models\Organisation;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

function systemRole(Role $role): RoleModel
{
    return RoleModel::query()->whereNull('team_id')->where('name', $role->value)->firstOrFail();
}

it('seeds the catalogue and the system roles once per test process', function (): void {
    expect(PermissionModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all())
        ->and(systemRole(Role::OrganisationAdmin)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::organisationAdmin())->map(fn (Permission $permission): string => $permission->value)->sort()->values()->all())
        ->and(systemRole(Role::SuperAdmin)->permissions)->toBeEmpty();
});

it('is idempotent', function (): void {
    $result = app(SyncPermissions::class)->handle();

    expect($result->changed())->toBeFalse()
        ->and(RoleModel::query()->whereNull('team_id')->count())->toBe(2);
});

it('prunes permissions that left the catalogue', function (): void {
    PermissionModel::query()->create(['name' => 'legacy.permission', 'guard_name' => 'web']);

    $result = app(SyncPermissions::class)->handle();

    expect($result->pruned)->toBe(['legacy.permission'])
        ->and(PermissionModel::query()->where('name', 'legacy.permission')->exists())->toBeFalse();
});

it('recreates a missing permission and repairs template drift (W7)', function (): void {
    PermissionModel::query()->where('name', Permission::UsersView->value)->delete();
    $template = systemRole(Role::OrganisationAdmin);
    $template->revokePermissionTo(Permission::RolesView->value);

    $result = app(SyncPermissions::class)->handle();

    expect($result->created)->toBe([Permission::UsersView->value])
        ->and($result->organisationAdminChanged)->toBeTrue()
        ->and($template->fresh()?->permissions->pluck('name')->all())->toContain(Permission::UsersView->value, Permission::RolesView->value);
});

it('recreates a deleted system role (W6)', function (): void {
    systemRole(Role::OrganisationAdmin)->delete();

    app(SyncPermissions::class)->handle();

    expect(systemRole(Role::OrganisationAdmin)->permissions)->toHaveCount(11);
});

it('strips super-only permissions from custom team roles', function (): void {
    $organisation = Organisation::factory()->create();
    $custom = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $custom->givePermissionTo(Permission::UsersView->value, Permission::OrganisationsCreate->value);

    $result = app(SyncPermissions::class)->handle();

    expect($result->superOnlyDetached)->toBe(1)
        ->and($custom->fresh()?->permissions->pluck('name')->all())->toBe([Permission::UsersView->value]);
});

it('runs as an artisan command', function (): void {
    expect(Artisan::call('permissions:sync'))->toBe(0);
});

it('reports the permissions it strips from the super admin badge (FU2-13)', function (): void {
    $badge = RoleModel::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->firstOrFail();
    PermissionsTeam::run(null, fn () => $badge->givePermissionTo(Permission::UsersView->value, Permission::RolesView->value));

    $result = app(SyncPermissions::class)->handle();

    expect($result->badgePermissionsStripped)->toBe(2)
        ->and($result->changed())->toBeTrue()
        ->and(app(SyncPermissions::class)->handle()->badgePermissionsStripped)->toBe(0);
});

it('lists every change it made when run as an artisan command (FU2-13)', function (): void {
    $badge = RoleModel::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->firstOrFail();
    PermissionsTeam::run(null, fn () => $badge->givePermissionTo(Permission::UsersView->value));

    expect(Artisan::call('permissions:sync'))->toBe(0)
        ->and(Artisan::output())->toContain('Permissions synced.', 'super admin badge permissions stripped');
});
