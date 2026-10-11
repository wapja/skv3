<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The user-management decisions (§4.4, RL-26), held once: UserPolicy asks for one target, index
 * pages for a page of users with one batch load. Super admins manage everyone but never disable or
 * delete themselves (W2). Other actors need the permission, the same organisation, a target that is
 * not a super admin and, for every write, a target whose effective permissions in that team are a
 * subset of their own, so a helpdesk role with users.update cannot touch an organisation admin.
 * Equal sets pass: organisation admins still manage each other (W12 guards the last one).
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
        return $this->forPage($actor, collect([$target]))[$target->id];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{view: bool, update: bool, updateStatus: bool, delete: bool, restore: bool}>
     */
    public function forPage(User $actor, Collection $users): array
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

    /** Organisation members never act on super admins, even promoted ones that keep an organisation_id (§6.3). */
    private function manages(User $actor, User $target): bool
    {
        return ! $target->isSuperAdmin()
            && $actor->organisation_id !== null
            && $actor->organisation_id === $target->organisation_id;
    }
}
