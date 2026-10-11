<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The permissions users hold in one team (RL-26): the permissions of the roles assigned with that
 * team id (the organisation_admin template included) plus the direct permissions of that team.
 * Read with explicit queries, never through Spatie relations or its cache, so a page of users costs
 * two queries and no model lazy-loads anything (RK-I6). The super_admin badge (null team) adds nothing.
 */
final class EffectivePermissions
{
    /** @return list<string> */
    public function of(User $user, int $teamId): array
    {
        return $this->forUsers([$user->id], $teamId)[$user->id] ?? [];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>> sorted permission names per user id; every id is a key
     */
    public function forUsers(array $userIds, int $teamId): array
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return [];
        }

        $morphClass = new User()->getMorphClass();

        $viaRoles = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $morphClass)
            ->whereIn('model_has_roles.model_id', $userIds)
            ->where('model_has_roles.team_id', $teamId)
            ->where('permissions.guard_name', SyncPermissions::GUARD)
            ->get(['model_has_roles.model_id as user_id', 'permissions.name as name']);

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $morphClass)
            ->whereIn('model_has_permissions.model_id', $userIds)
            ->where('model_has_permissions.team_id', $teamId)
            ->where('permissions.guard_name', SyncPermissions::GUARD)
            ->get(['model_has_permissions.model_id as user_id', 'permissions.name as name']);

        $names = [];

        foreach ($viaRoles->concat($direct) as $row) {
            $names[(int) $row->user_id][] = (string) $row->name;
        }

        $sets = [];

        foreach ($userIds as $userId) {
            $set = array_values(array_unique($names[$userId] ?? []));
            sort($set);
            $sets[$userId] = $set;
        }

        return $sets;
    }

    /**
     * The users among $userIds that hold the organisation_admin template in this team, by role id
     * (a team role that is merely named like it never counts, FU2-4).
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function holdsTemplate(array $userIds, int $teamId): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereNull('roles.team_id')
            ->where('roles.name', Role::OrganisationAdmin->value)
            ->where('roles.guard_name', SyncPermissions::GUARD)
            ->where('model_has_roles.model_type', new User()->getMorphClass())
            ->where('model_has_roles.team_id', $teamId)
            ->whereIn('model_has_roles.model_id', $userIds)
            ->orderBy('model_has_roles.model_id')
            ->pluck('model_has_roles.model_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all());
    }
}
