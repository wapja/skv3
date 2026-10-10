<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent catalogue sync (§6.3, DEC-21 option A): upserts the Permission enum, prunes
 * removed permissions, keeps the global organisation_admin template on the organisation-admin
 * set, keeps the super_admin badge without permissions and strips super-only permissions from
 * custom team roles. Runs in setup, on deploy, via doctor --fix and in EssentialsSeeder.
 */
final class SyncPermissions
{
    public const string GUARD = 'web';

    public function handle(): SyncResult
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $result = PermissionsTeam::run(null, fn (): SyncResult => DB::transaction(function (): SyncResult {
            $created = $this->upsertPermissions();
            $pruned = $this->prunePermissions();
            $organisationAdminChanged = $this->syncOrganisationAdmin();
            $this->systemRole(Role::SuperAdmin)->syncPermissions([]);
            $superOnlyDetached = $this->detachSuperOnlyFromTeamRoles();

            return new SyncResult($created, $pruned, $organisationAdminChanged, $superOnlyDetached, 0);
        }));

        $registrar->forgetCachedPermissions();

        return $result;
    }

    /** @return list<string> */
    private function upsertPermissions(): array
    {
        $existing = PermissionModel::query()->where('guard_name', self::GUARD)->pluck('name')->all();
        $created = [];

        foreach (Permission::values() as $name) {
            if (! in_array($name, $existing, true)) {
                PermissionModel::query()->create(['name' => $name, 'guard_name' => self::GUARD]);
                $created[] = $name;
            }
        }

        return $created;
    }

    /** @return list<string> */
    private function prunePermissions(): array
    {
        $stale = array_values(PermissionModel::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', Permission::values())
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());

        PermissionModel::query()->where('guard_name', self::GUARD)->whereIn('name', $stale)->delete();

        return $stale;
    }

    private function syncOrganisationAdmin(): bool
    {
        $template = $this->systemRole(Role::OrganisationAdmin);
        $wanted = array_map(fn (Permission $permission): string => $permission->value, Permission::organisationAdmin());
        $current = $template->permissions()->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();

        sort($wanted);
        sort($current);

        if ($wanted === $current) {
            return false;
        }

        $template->syncPermissions($wanted);

        return true;
    }

    private function detachSuperOnlyFromTeamRoles(): int
    {
        $superOnly = array_values(array_map(
            fn (Permission $permission): string => $permission->value,
            array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->superOnly()),
        ));

        return DB::table('role_has_permissions')
            ->whereIn('permission_id', PermissionModel::query()->whereIn('name', $superOnly)->select('id'))
            ->whereIn('role_id', RoleModel::query()->whereNotNull('team_id')->select('id'))
            ->delete();
    }

    private function systemRole(Role $role): RoleModel
    {
        $existing = RoleModel::query()
            ->whereNull('team_id')
            ->where('name', $role->value)
            ->where('guard_name', self::GUARD)
            ->first();

        return $existing ?? RoleModel::query()->create([
            'name' => $role->value,
            'guard_name' => self::GUARD,
            'team_id' => null,
        ]);
    }
}
