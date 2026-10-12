<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Authorization\LockoutGuard;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Enums\ActivityEvent;
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
 * The only way to change a user's organisation roles (§4.6). Accepts web-guard roles of the target's own
 * team plus the global organisation_admin template, matched by id (DEC-21 option A, FU2-4); the
 * super_admin badge, other teams' roles, other guards' roles and team roles named like a system role are
 * rejected, and non-super-admins only hand out roles whose permissions they hold (W1). Refuses removing
 * your own organisation admin role and demoting the last active organisation admin (W12). Records
 * user.roles_changed with the old and new role names when the set changes (§4.8).
 */
final readonly class UpdateUserRoles
{
    public function __construct(private LockoutGuard $lockoutGuard, private RecordActivity $recordActivity) {}

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
        $templateId = Role::templateId();
        $roles = $this->resolveRoles($teamId, $templateId, $roleIds);
        $keepsAdmin = $roles->contains(fn (RoleModel $role): bool => (int) $role->getKey() === $templateId);

        PermissionsTeam::run($teamId, function () use ($actor, $target, $roles, $keepsAdmin, $teamId, $templateId): void {
            DB::transaction(function () use ($actor, $target, $roles, $keepsAdmin, $teamId, $templateId): void {
                $target->unsetRelation('roles');
                $before = $this->roleNamesOf($target, $teamId);

                // RK-B3 (FU4-7): a pending user's roles stay as invited; the accept link was issued for them.
                // InviteUser's first assignment, on a fresh invitee without roles, stays allowed.
                if ($target->status === UserStatus::PendingActivation && $before !== []) {
                    throw ValidationException::withMessages([
                        'roles' => __('A pending user keeps the roles they were invited with. Cancel and re-invite to change them.'),
                    ]);
                }

                $this->guardEscalation($actor, $target, $roles);

                if (! $keepsAdmin && $this->holdsTemplate($target, $teamId, $templateId)) {
                    $this->guardAdminRemoval($actor, $target, $teamId);
                }

                $target->syncRoles($roles);
                $target->unsetRelation('roles');

                $after = $roles->map(fn (RoleModel $role): string => $role->name)->values()->all();
                sort($after);

                if ($before !== $after) {
                    $this->recordActivity->handle(
                        ActivityEvent::UserRolesChanged,
                        $target,
                        changes: ['old' => ['roles' => $before], 'attributes' => ['roles' => $after]],
                        causer: $actor,
                    );
                }
            });
        });
    }

    /**
     * @param  list<int>  $roleIds
     * @return Collection<int, RoleModel>
     *
     * @throws ValidationException
     */
    private function resolveRoles(int $teamId, int $templateId, array $roleIds): Collection
    {
        $roleIds = array_values(array_unique($roleIds));

        $roles = RoleModel::query()
            ->whereKey($roleIds)
            ->where('guard_name', SyncPermissions::GUARD)
            ->where(function (Builder $query) use ($teamId, $templateId): void {
                $query->where('team_id', $teamId)->orWhere('id', $templateId);
            })
            ->get();

        // A team role that reads like a system role would pass for it in lists and checks (FU2-4, W6).
        $lookalike = $roles->contains(
            fn (RoleModel $role): bool => (int) $role->getKey() !== $templateId && Role::isReservedName($role->name),
        );

        if ($lookalike || $roles->count() !== count($roleIds)) {
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

        $this->lockoutGuard->ensureAnotherActiveOrganisationAdmin($target, $teamId, 'roles');
    }

    /** The template by id, in this team: a team role that merely shares its name does not count (FU2-4). */
    private function holdsTemplate(User $target, int $teamId, int $templateId): bool
    {
        return DB::table('model_has_roles')
            ->where('role_id', $templateId)
            ->where('model_type', $target->getMorphClass())
            ->where('model_id', $target->id)
            ->where('team_id', $teamId)
            ->exists();
    }

    /** @return list<string> sorted in PHP, so both databases give the same order */
    private function roleNamesOf(User $target, int $teamId): array
    {
        $names = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $target->getMorphClass())
            ->where('model_has_roles.model_id', $target->id)
            ->where('model_has_roles.team_id', $teamId)
            ->pluck('roles.name')
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
        sort($names);

        return $names;
    }
}
