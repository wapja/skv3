<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Input rules and lookups shared by the custom-role actions (§4.6, RL-19): trimmed names that
 * are not a system role's name and unique per team, permissions from the role editor's set,
 * and role lookups that never leave the organisation's team (W1).
 */
trait ManagesCustomRoles
{
    /**
     * The trimmed name, refused when empty, longer than 255, reserved for a system role in any
     * case or spacing (W6) or already used in the team (case-insensitive).
     *
     * @throws ValidationException
     */
    protected function validatedRoleName(string $name, int $teamId, ?int $ignoreRoleId = null): string
    {
        $name = trim($name);

        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:255']])->validate();

        if (Role::isReservedName($name)) {
            throw ValidationException::withMessages(['name' => __('This name is reserved for a system role.')]);
        }

        $query = RoleModel::query()->where('team_id', $teamId);

        if ($ignoreRoleId !== null) {
            $query->whereKeyNot($ignoreRoleId);
        }

        // Compared in PHP: a LIKE would treat the underscore in a name as a wildcard (RL-19).
        $taken = $query->pluck('name')
            ->contains(fn (mixed $existing): bool => mb_strtolower((string) $existing) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('This organisation already has a role with this name.')]);
        }

        return $name;
    }

    /**
     * The permission names to store, sorted and without duplicates. Anything the role editor does
     * not offer (super-only, or users.impersonate while the module is off) is refused (ROLE-9, ROLE-17).
     *
     * @param  list<Permission>  $permissions
     * @return list<string>
     *
     * @throws ValidationException
     */
    protected function assignablePermissionValues(array $permissions): array
    {
        $assignable = Permission::assignableToCustomRoles();

        foreach ($permissions as $permission) {
            if (! in_array($permission, $assignable, true)) {
                throw ValidationException::withMessages([
                    'permissions' => __('One or more permissions cannot be given to a custom role.'),
                ]);
            }
        }

        $values = array_values(array_unique(array_map(fn (Permission $permission): string => $permission->value, $permissions)));
        sort($values);

        return $values;
    }

    /**
     * The role with this id in the organisation's team, locked; or one of the two system roles
     * (team_id NULL), so callers refuse those by name. Any other team's role is not found (W1).
     *
     * @throws ModelNotFoundException
     */
    protected function lockedRole(Organisation $organisation, int $roleId): RoleModel
    {
        return RoleModel::query()
            ->whereKey($roleId)
            ->where(function (Builder $query) use ($organisation): void {
                $query->where('team_id', $organisation->id)->orWhereNull('team_id');
            })
            ->lockForUpdate()
            ->firstOrFail();
    }
}
