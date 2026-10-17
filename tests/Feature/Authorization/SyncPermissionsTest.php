<?php

declare(strict_types=1);

/** covers §4.6.1, §4.6.3, §4.6.5, §4.6.9, §4.12.1, §6.3 */

use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

/*
| The rows the sync owns and the writes a run issues (FU2-11): an idempotent run reports no change, leaves the rows as
| they were (ids and timestamps included) and inserts or updates nothing, so a detach-and-reattach on every run, which
| leaves the same pivot rows behind, is caught too.
*/
$syncAgain = function (): array {
    $rows = fn (): array => collect(['permissions', 'roles', 'role_has_permissions', 'model_has_roles'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()->map(fn (object $row): array => (array) $row)->sortBy(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all()])
        ->all();
    $before = $rows();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $changed = app(SyncPermissions::class)->handle()->changed();

    return ['changed' => $changed, 'before' => $before, 'after' => $rows(), 'writes' => $writes];
};

it('is idempotent: a second run changes no row and writes nothing (FU2-11)', function () use ($syncAgain): void {
    $run = $syncAgain();

    expect($run['changed'])->toBeFalse()
        ->and($run['after'])->toBe($run['before'])
        ->and($run['writes'])->toBe([])
        ->and(RoleModel::query()->whereNull('team_id')->count())->toBe(2);
});

it('reports nothing left to repair on the run after a drift repair (FU2-11)', function () use ($syncAgain): void {
    PermissionModel::query()->where('name', Permission::UsersView->value)->delete();
    systemRole(Role::OrganisationAdmin)->revokePermissionTo(Permission::RolesView->value);
    $badge = systemRole(Role::SuperAdmin);
    PermissionsTeam::run(null, fn (): RoleModel => $badge->givePermissionTo(Permission::RolesView->value));
    // A flagged user without the badge: the factory writes the flag directly, as an old import would.
    User::factory()->superAdmin()->create();

    expect(app(SyncPermissions::class)->handle()->changed())->toBeTrue();

    $run = $syncAgain();

    expect($run['changed'])->toBeFalse()
        ->and($run['after'])->toBe($run['before'])
        ->and($run['writes'])->toBe([]);
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
