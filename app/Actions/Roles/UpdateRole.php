<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Audit\RecordActivity;
use App\Authorization\Permission;
use App\Concerns\ManagesCustomRoles;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Renames a custom role and sets its permissions (§4.6). The role is looked up in the
 * organisation's team only (W1); system roles are refused for everyone, super admins included
 * (W6). Permissions the role holds but the editor hides right now (users.impersonate while the
 * module is off) are kept, so a save never strips them (§6.8, CV-4).
 */
final readonly class UpdateRole
{
    use ManagesCustomRoles;

    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @param  list<Permission>  $permissions
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, int $roleId, string $name, array $permissions): RoleModel
    {
        $values = $this->assignablePermissionValues($permissions);

        return DB::transaction(function () use ($actor, $organisation, $roleId, $name, $values): RoleModel {
            $organisation = HostScope::lockForWrite($organisation);
            $role = $this->lockedRole($organisation, $roleId);

            Gate::forUser($actor)->authorize('update', $role);

            // Gate::before lets super admins through update, so the system-role guard lives here (W6).
            if ($role->getAttribute('team_id') === null) {
                throw ValidationException::withMessages(['role' => __('System roles cannot be changed.')]);
            }

            $old = ['name' => $role->name, 'permissions' => $this->permissionValuesOf($role)];
            $new = [
                'name' => $this->validatedRoleName($name, $organisation->id, (int) $role->getKey()),
                'permissions' => $this->savedPermissionValues($old['permissions'], $values),
            ];

            try {
                $role->forceFill(['name' => $new['name']])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('This organisation already has a role with this name.')]);
            }

            $role->syncPermissions($new['permissions']);
            $role->unsetRelation('permissions');

            $changes = $this->changes($old, $new);

            if ($changes['old'] !== []) {
                $this->recordActivity->handle(
                    ActivityEvent::RoleUpdated,
                    $role,
                    changes: $changes,
                    causer: $actor,
                    organisation: $organisation,
                );
            }

            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

            return $role;
        });
    }

    /** @return list<string> */
    private function permissionValuesOf(RoleModel $role): array
    {
        $values = $role->permissions()->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();
        sort($values);

        return $values;
    }

    /**
     * The posted permissions plus the held ones the editor hides right now (CV-4).
     *
     * @param  list<string>  $held
     * @param  list<string>  $posted
     * @return list<string>
     */
    private function savedPermissionValues(array $held, array $posted): array
    {
        $offered = array_map(fn (Permission $permission): string => $permission->value, Permission::assignableToCustomRoles());
        $teamPermissions = array_map(fn (Permission $permission): string => $permission->value, Permission::organisationAdmin());
        $hidden = array_diff($teamPermissions, $offered);

        $saved = array_values(array_unique([...$posted, ...array_intersect($held, $hidden)]));
        sort($saved);

        return $saved;
    }

    /**
     * The changed keys only, as the audit diff (ROLE-19).
     *
     * @param  array{name: string, permissions: list<string>}  $old
     * @param  array{name: string, permissions: list<string>}  $new
     * @return array{old: array<string, mixed>, attributes: array<string, mixed>}
     */
    private function changes(array $old, array $new): array
    {
        $changes = ['old' => [], 'attributes' => []];

        foreach ($new as $key => $value) {
            if ($old[$key] !== $value) {
                $changes['old'][$key] = $old[$key];
                $changes['attributes'][$key] = $value;
            }
        }

        return $changes;
    }
}
