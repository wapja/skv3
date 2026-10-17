<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place where user administration (status, delete, roles, super admin) locks users rows, and the lock-out
 * guards (§4.4, §4.6, §6.3): never remove the last active, non-deleted super admin or the last active organisation
 * admin of a team. Invitations lock the invitee's row alone and archive/purge the organisation's users (CD-5).
 *
 * A write locks its users rows once, in one batch (CD-5 step 3): one SELECT … FOR UPDATE on users only (no join),
 * in id order, after the organisation lock (step 1) and the role locks (step 2). The batch holds the target and
 * every candidate a guard weighs, so the guards decide in PHP on locked rows (RL-27, FU2-5), and two batches of
 * different writes never lock the same rows in a different order (G4-06). pluck()/get() instead of count() or
 * exists(): PostgreSQL refuses FOR UPDATE with aggregates and in EXISTS subqueries.
 *
 * @phpstan-type LockedUser array{email: string, status: UserStatus, is_super_admin: bool, organisation_id: int|null, deleted: bool, team_admin: bool}
 */
final readonly class LockoutGuard
{
    public function __construct(private EffectivePermissions $effectivePermissions) {}

    /**
     * Locks the users batch of a write and returns the locked rows by id: $userIds, plus every non-deleted super
     * admin when $withSuperAdmins, plus every holder of the organisation_admin template (by role id) in
     * $teamAdminsOf, whose rows say team_admin. The template holders are read just before the lock; that read is
     * stable because every writer of a team's role assignments holds the organisation's row lock first (CD-5 step 1).
     *
     * @param  list<int>  $userIds
     * @return array<int, LockedUser>
     */
    public function lockUsers(array $userIds, bool $withSuperAdmins = false, ?int $teamAdminsOf = null): array
    {
        $teamAdminIds = $teamAdminsOf === null ? [] : $this->templateHolderIds($teamAdminsOf);
        $ids = array_values(array_unique([...$userIds, ...$teamAdminIds]));

        $rows = DB::table('users')
            ->where(function (Builder $query) use ($ids, $withSuperAdmins): void {
                $query->whereIn('id', $ids);

                if ($withSuperAdmins) {
                    $query->orWhere(function (Builder $superAdmins): void {
                        $superAdmins->where('is_super_admin', true)->whereNull('deleted_at');
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'email', 'status', 'is_super_admin', 'organisation_id', 'deleted_at']);

        $locked = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $locked[$id] = [
                'email' => (string) $row->email,
                'status' => UserStatus::from((string) $row->status),
                'is_super_admin' => (bool) $row->is_super_admin,
                'organisation_id' => $row->organisation_id === null ? null : (int) $row->organisation_id,
                'deleted' => $row->deleted_at !== null,
                'team_admin' => in_array($id, $teamAdminIds, true),
            ];
        }

        return $locked;
    }

    /**
     * The users batch for disabling or deleting $target (W2, W12): the target, every super admin when the target's
     * stored flag is set, and the template holders of the target's team when the target holds the template there.
     * The flag and the template are read just before the lock; under the organisation lock the caller holds (CD-5
     * step 1) neither can change, because promotions and role writes take that lock too, and a user without an
     * organisation is a super admin for good (W8).
     *
     * @return array<int, LockedUser>
     */
    public function lockForRemoval(User $target): array
    {
        $stored = DB::table('users')->where('id', $target->id)->first(['is_super_admin', 'organisation_id']);

        if ($stored === null) {
            return [];
        }

        $teamId = $stored->organisation_id === null ? null : (int) $stored->organisation_id;
        $holdsTemplate = $teamId !== null && $this->effectivePermissions->holdsTemplate([$target->id], $teamId) !== [];

        return $this->lockUsers([$target->id], withSuperAdmins: (bool) $stored->is_super_admin, teamAdminsOf: $holdsTemplate ? $teamId : null);
    }

    /**
     * The policy's preconditions for writing a user, decided again on the target's locked row (FU2-5): a promotion, a
     * move or a deletion that committed while the write waited for its locks leaves the model the policy read stale.
     * Only a super admin acts on a super admin (§6.3), and nobody writes a user who left the organisation the write
     * locked or was deleted.
     *
     * @param  LockedUser  $stored
     *
     * @throws AuthorizationException
     */
    public function ensureStillManageable(User $actor, User $target, array $stored): void
    {
        if (($stored['is_super_admin'] && ! $actor->isSuperAdmin()) || $stored['organisation_id'] !== $target->organisation_id || $stored['deleted']) {
            throw new AuthorizationException;
        }
    }

    /**
     * Both guards for removing an active user (disabling or deleting, W2, W12), on the rows lockForRemoval() locked:
     * the super-admin guard when the target's locked row carries the flag, the organisation-admin guard when it
     * holds the template in its team.
     *
     * @param  array<int, LockedUser>  $locked
     *
     * @throws ValidationException
     */
    public function ensureAnotherActiveAdmin(User $target, array $locked, string $field): void
    {
        $row = $locked[$target->id] ?? null;

        if ($row === null) {
            return;
        }

        if ($row['is_super_admin']) {
            $this->ensureAnotherActiveSuperAdmin($target, $locked, $field);
        }

        if ($row['team_admin']) {
            $this->ensureAnotherActiveOrganisationAdmin($target, $locked, $field);
        }
    }

    /**
     * @param  array<int, LockedUser>  $locked  a batch locked with $withSuperAdmins
     *
     * @throws ValidationException
     */
    public function ensureAnotherActiveSuperAdmin(User $user, array $locked, string $field): void
    {
        if (! $this->anotherActiveRemains($locked, $user, 'is_super_admin')) {
            throw ValidationException::withMessages([
                $field => __('At least one active super admin must remain.'),
            ]);
        }
    }

    /**
     * @param  array<int, LockedUser>  $locked  a batch locked with $teamAdminsOf
     *
     * @throws ValidationException
     */
    public function ensureAnotherActiveOrganisationAdmin(User $user, array $locked, string $field): void
    {
        if (! $this->anotherActiveRemains($locked, $user, 'team_admin')) {
            throw ValidationException::withMessages([
                $field => __('An organisation needs at least one active organisation admin.'),
            ]);
        }
    }

    /** @return list<int> the holders of the organisation_admin template in this team, by role id (FU2-4) */
    private function templateHolderIds(int $teamId): array
    {
        return array_values(DB::table('model_has_roles')
            ->where('role_id', Role::templateId())
            ->where('model_type', new User()->getMorphClass())
            ->where('team_id', $teamId)
            ->orderBy('model_id')
            ->pluck('model_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all());
    }

    /**
     * @param  array<int, LockedUser>  $locked
     * @param  'is_super_admin'|'team_admin'  $role
     */
    private function anotherActiveRemains(array $locked, User $user, string $role): bool
    {
        return array_any(
            $locked,
            fn (array $row, int $id): bool => $id !== $user->id && $row[$role] && ! $row['deleted'] && $row['status'] === UserStatus::Active,
        );
    }
}
