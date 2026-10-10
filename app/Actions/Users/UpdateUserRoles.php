<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * The only way to change a user's organisation roles (§4.6). Accepts roles of the target's
 * own team plus the global organisation_admin template (DEC-21 option A); the super_admin badge
 * and other teams' roles are rejected, and non-super-admins only hand out roles whose
 * permissions they hold (W1). Refuses removing your own organisation admin role and demoting
 * the last active organisation admin (W12).
 */
final class UpdateUserRoles
{
    /**
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, array $roleIds): void
    {
        Gate::forUser($actor)->authorize('updateRoles', $target);

        $teamId = $target->organisation_id ?? throw new LogicException('updateRoles allows only users with an organisation.');
        $roles = $this->resolveRoles($teamId, $roleIds);
        $keepsAdmin = $roles->contains(fn (RoleModel $role): bool => $role->name === Role::OrganisationAdmin->value);

        PermissionsTeam::run($teamId, function () use ($actor, $target, $roles, $keepsAdmin, $teamId): void {
            DB::transaction(function () use ($actor, $target, $roles, $keepsAdmin, $teamId): void {
                $target->unsetRelation('roles');
                $this->guardEscalation($actor, $target, $roles);

                if (! $keepsAdmin && $target->hasRole(Role::OrganisationAdmin->value)) {
                    $this->guardAdminRemoval($actor, $target, $teamId);
                }

                $target->syncRoles($roles);
                $target->unsetRelation('roles');
            });
        });
    }

    /**
     * @param  list<int>  $roleIds
     * @return Collection<int, RoleModel>
     *
     * @throws ValidationException
     */
    private function resolveRoles(int $teamId, array $roleIds): Collection
    {
        $roleIds = array_values(array_unique($roleIds));

        $roles = RoleModel::query()
            ->whereKey($roleIds)
            ->where(function (Builder $query) use ($teamId): void {
                $query->where('team_id', $teamId)->orWhere(function (Builder $template): void {
                    $template->whereNull('team_id')->where('name', Role::OrganisationAdmin->value);
                });
            })
            ->get();

        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages([
                'roles' => __('One or more roles are not available for this organisation.'),
            ]);
        }

        return $roles;
    }

    /**
     * Non-super-admins may only give or take away roles whose permissions they hold themselves,
     * so roles.assign alone never leads to organisation admin (W1).
     *
     * @param  Collection<int, RoleModel>  $roles
     *
     * @throws ValidationException
     */
    private function guardEscalation(User $actor, User $target, Collection $roles): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $currentIds = $target->roles()->pluck('roles.id')->map(fn (mixed $id): int => (int) $id)->all();
        $newIds = $roles->map(fn (RoleModel $role): int => (int) $role->getKey())->all();
        $changedIds = array_values(array_unique([...array_diff($newIds, $currentIds), ...array_diff($currentIds, $newIds)]));

        $actor->unsetRelation('roles');

        foreach (RoleModel::query()->whereKey($changedIds)->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                if (! $actor->checkPermissionTo($permission->name)) {
                    throw ValidationException::withMessages([
                        'roles' => __('You can only give or take away roles whose permissions you have yourself.'),
                    ]);
                }
            }
        }
    }

    /** @throws ValidationException */
    private function guardAdminRemoval(User $actor, User $target, int $teamId): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'roles' => __('You cannot remove your own organisation admin role.'),
            ]);
        }

        $templateId = RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

        // pluck() instead of exists(): keeps FOR UPDATE out of an EXISTS subquery on PostgreSQL.
        $otherActiveAdmins = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.role_id', $templateId)
            ->where('model_has_roles.model_type', $target->getMorphClass())
            ->where('model_has_roles.team_id', $teamId)
            ->where('users.id', '!=', $target->id)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->lockForUpdate()
            ->pluck('users.id');

        if ($otherActiveAdmins->isEmpty()) {
            throw ValidationException::withMessages([
                'roles' => __('An organisation needs at least one active organisation admin.'),
            ]);
        }
    }
}
