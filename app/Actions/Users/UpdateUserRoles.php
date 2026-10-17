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
use App\Tenancy\HostScope;
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
 * user.roles_changed with the old and new role names when the set changes (§4.8). The locks follow CD-5: the
 * organisation, the assigned roles, then one users batch; decisions read the locked rows (FU2-5), the policy's
 * 'not a super admin, still in this organisation, not deleted' included.
 *
 * @phpstan-import-type LockedUser from LockoutGuard
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
        $roleIds = array_values(array_unique($roleIds));

        PermissionsTeam::run($teamId, function () use ($actor, $target, $roleIds, $teamId, $templateId): void {
            DB::transaction(function () use ($actor, $target, $roleIds, $teamId, $templateId): void {
                // CD-5: the organisation, then the roles it assigns, then one users batch, before any role
                // assignment is written. A concurrent DeleteRole holds the same organisation lock, so it can neither
                // deadlock with this write nor delete a role between the lookup and the insert (cycle 4).
                HostScope::lockOrganisation($teamId);
                $roles = $this->lockedRoles($teamId, $templateId, $roleIds);
                $keepsAdmin = $roles->contains(fn (RoleModel $role): bool => (int) $role->getKey() === $templateId);
                $removesAdmin = ! $keepsAdmin && $this->holdsTemplate($target, $teamId, $templateId);
                $locked = $this->lockoutGuard->lockUsers([$target->id], teamAdminsOf: $removesAdmin ? $teamId : null);
                $stored = $locked[$target->id] ?? null;

                if ($stored === null) {
                    return;
                }

                // FU2-5: the policy's preconditions, decided again on the locked row; a promotion, a move or a
                // deletion that committed while this write waited for the organisation lock is seen here.
                if ($stored['is_super_admin'] || $stored['organisation_id'] !== $teamId || $stored['deleted']) {
                    throw new AuthorizationException;
                }

                $this->guardInvitedRoles($target, $stored['status']);

                $target->unsetRelation('roles');
                $before = $this->roleNamesOf($target, $teamId);

                $this->guardEscalation($actor, $target, $roles);

                if ($removesAdmin) {
                    $this->guardAdminRemoval($actor, $target, $locked);
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
     * The requested roles, locked in id order (CD-5 step 2); none when none are requested.
     *
     * @param  list<int>  $roleIds
     * @return Collection<int, RoleModel>
     *
     * @throws ValidationException
     */
    private function lockedRoles(int $teamId, int $templateId, array $roleIds): Collection
    {
        if ($roleIds === []) {
            return new Collection;
        }

        $roles = RoleModel::query()
            ->whereKey($roleIds)
            ->where('guard_name', SyncPermissions::GUARD)
            ->where(function (Builder $query) use ($teamId, $templateId): void {
                $query->where('team_id', $teamId)->orWhere('id', $templateId);
            })
            ->orderBy('id')
            ->lockForUpdate()
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
     * RK-B3 (FU4-7, G2-06): a pending user's roles stay as invited; the accept link was issued for them. Only
     * InviteUser's first assignment passes, made before the invitation row exists. Decided on the locked status
     * and the invitation, never on the roles the user still holds: deleting a custom role can leave an invitee
     * without any (DeleteRole), which must not reopen the assignment. The invitation read needs no lock of its own:
     * every write of an organisation's invitations holds the organisation lock taken above.
     *
     * @throws ValidationException
     */
    private function guardInvitedRoles(User $target, UserStatus $storedStatus): void
    {
        if ($storedStatus !== UserStatus::PendingActivation) {
            return;
        }

        if (DB::table('invitations')->where('user_id', $target->id)->exists()) {
            throw ValidationException::withMessages([
                'roles' => __('A pending user keeps the roles they were invited with. Cancel and re-invite to change them.'),
            ]);
        }
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

    /**
     * @param  array<int, LockedUser>  $locked  the batch, the team's template holders included
     *
     * @throws ValidationException
     */
    private function guardAdminRemoval(User $actor, User $target, array $locked): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'roles' => __('You cannot remove your own organisation admin role.'),
            ]);
        }

        $this->lockoutGuard->ensureAnotherActiveOrganisationAdmin($target, $locked, 'roles');
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
