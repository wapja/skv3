<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Collection;

/**
 * The user-management decisions (§4.4, RL-26), held once: UserPolicy asks for one target, index
 * pages for a page of users with one batch load. Super admins manage everyone but never disable or
 * delete themselves (W2). Other actors need the permission, the same organisation, a target that is
 * not a super admin and, for every write, a target whose effective permissions in that team are a
 * subset of their own, so a helpdesk role with users.update cannot touch an organisation admin.
 * Equal sets pass: organisation admins still manage each other (W12 guards the last one).
 *
 * Impersonation (§4.7) follows the same principle with stricter targets: see mayImpersonate().
 */
final readonly class UserAbilities
{
    public function __construct(private EffectivePermissions $permissions) {}

    /**
     * @param  list<string>  $actorPermissions
     * @param  list<string>  $targetPermissions
     */
    public function covers(array $actorPermissions, array $targetPermissions): bool
    {
        return array_diff($targetPermissions, $actorPermissions) === [];
    }

    /** @return array{view: bool, update: bool, updateStatus: bool, delete: bool, restore: bool} */
    public function forTarget(User $actor, User $target): array
    {
        return $this->managementRows($actor, collect([$target]))[$target->id];
    }

    /**
     * Every control of an index row, with the impersonate column equal to UserPolicy::impersonate.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{view: bool, update: bool, updateStatus: bool, delete: bool, restore: bool, impersonate: bool}>
     */
    public function forPage(User $actor, Collection $users): array
    {
        $impersonate = $this->impersonationColumn($actor, $users);
        $rows = [];

        foreach ($this->managementRows($actor, $users) as $userId => $row) {
            $rows[$userId] = [...$row, 'impersonate' => $impersonate[$userId] ?? false];
        }

        return $rows;
    }

    /**
     * The impersonation matrix (§4.7, IMP-4, IMP-5, C-4, X6) for one pair, without the module and depth
     * clauses that UserPolicy::impersonate adds. Only on a tenant host, and only for a target of that
     * organisation who is active, not deleted, not a super admin and not the actor. A super admin may then
     * impersonate anyone; everyone else needs users.impersonate in the organisation, a target without the
     * organisation_admin template (by role id, W6) and a target whose effective permissions — role and
     * direct ones, read with explicit queries (RK-I6) — they hold themselves.
     */
    public function mayImpersonate(User $actor, User $target): bool
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Organisation || ! $this->isImpersonationTarget($actor, $target, $tenant)) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        if ($actor->organisation_id !== $tenant->id) {
            return false;
        }

        $sets = $this->permissions->forUsers([$actor->id, $target->id], $tenant->id);

        return $this->memberMayImpersonate(
            $sets[$actor->id] ?? [],
            $sets[$target->id] ?? [],
            $this->permissions->holdsTemplate([$target->id], $tenant->id) !== [],
        );
    }

    /**
     * view, update, updateStatus, delete and restore for every given user (Task 4).
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{view: bool, update: bool, updateStatus: bool, delete: bool, restore: bool}>
     */
    private function managementRows(User $actor, Collection $users): array
    {
        if ($actor->isSuperAdmin()) {
            return $users->mapWithKeys(fn (User $user): array => [$user->id => [
                'view' => true,
                'update' => true,
                'updateStatus' => $actor->isNot($user),
                'delete' => $actor->isNot($user),
                'restore' => true,
            ]])->all();
        }

        $managed = $users->filter(fn (User $user): bool => $this->manages($actor, $user));
        $sets = $actor->organisation_id === null || $managed->isEmpty()
            ? []
            : $this->permissions->forUsers([$actor->id, ...array_values($managed->map(fn (User $user): int => $user->id)->all())], $actor->organisation_id);
        $actorPermissions = $sets[$actor->id] ?? [];

        $mayView = $actor->can(Permission::UsersView);
        $mayUpdate = $actor->can(Permission::UsersUpdate);
        $mayDelete = $actor->can(Permission::UsersDelete);

        $abilities = [];

        foreach ($users as $user) {
            $manages = $this->manages($actor, $user);
            $covered = $manages && $this->covers($actorPermissions, $sets[$user->id] ?? []);
            $isSelf = $actor->is($user);

            $abilities[$user->id] = [
                'view' => $mayView && $manages,
                'update' => $mayUpdate && $covered,
                'updateStatus' => $mayUpdate && $covered && ! $isSelf,
                'delete' => $mayDelete && $covered && ! $isSelf,
                'restore' => $mayDelete && $covered,
            ];
        }

        return $abilities;
    }

    /**
     * UserPolicy::impersonate for a page of users, from one batch of queries (RK-I6). Users missing from
     * the result may not be impersonated.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, bool>
     */
    private function impersonationColumn(User $actor, Collection $users): array
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Organisation || ! Impersonation::enabled() || Impersonation::isActive()) {
            return [];
        }

        $candidates = $users->filter(fn (User $user): bool => $this->isImpersonationTarget($actor, $user, $tenant));

        if ($candidates->isEmpty()) {
            return [];
        }

        if ($actor->isSuperAdmin()) {
            return $candidates->mapWithKeys(fn (User $user): array => [$user->id => true])->all();
        }

        if ($actor->organisation_id !== $tenant->id) {
            return [];
        }

        $ids = array_values($candidates->map(fn (User $user): int => $user->id)->all());
        $sets = $this->permissions->forUsers([$actor->id, ...$ids], $tenant->id);
        $templateHolders = $this->permissions->holdsTemplate($ids, $tenant->id);

        return $candidates->mapWithKeys(fn (User $user): array => [$user->id => $this->memberMayImpersonate(
            $sets[$actor->id] ?? [],
            $sets[$user->id] ?? [],
            in_array($user->id, $templateHolders, true),
        )])->all();
    }

    /** A target of this host's organisation who could be impersonated by someone. */
    private function isImpersonationTarget(User $actor, User $target, Organisation $tenant): bool
    {
        return $actor->isNot($target)
            && $target->organisation_id === $tenant->id
            && $target->isActive()
            && ! $target->trashed()
            && ! $target->isSuperAdmin();
    }

    /**
     * The rule for actors who are not super admins: users.impersonate, a "non-admin" target (no template
     * role) and the permission-subset rule.
     *
     * @param  list<string>  $actorPermissions
     * @param  list<string>  $targetPermissions
     */
    private function memberMayImpersonate(array $actorPermissions, array $targetPermissions, bool $targetHoldsTemplate): bool
    {
        return in_array(Permission::UsersImpersonate->value, $actorPermissions, true)
            && ! $targetHoldsTemplate
            && $this->covers($actorPermissions, $targetPermissions);
    }

    /** Organisation members never act on super admins, even promoted ones that keep an organisation_id (§6.3). */
    private function manages(User $actor, User $target): bool
    {
        return ! $target->isSuperAdmin()
            && $actor->organisation_id !== null
            && $actor->organisation_id === $target->organisation_id;
    }
}
