<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * §4.6: roles are viewed with roles.view and managed with roles.manage (super-only). For
 * non-super actors a role must belong to their own organisation's team (RK-M9). System roles
 * (team_id NULL) are protected: delete is always checked and refuses them to everyone; update
 * passes super admins through Gate::before, so UpdateRole refuses system roles itself (W6).
 */
final class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::RolesView);
    }

    /** Super admins pass through Gate::before. */
    public function create(User $actor): bool
    {
        return $actor->can(Permission::RolesManage);
    }

    /** Super admins pass through Gate::before; UpdateRole refuses system roles (W6). */
    public function update(User $actor, RoleModel $role): bool
    {
        return $actor->can(Permission::RolesManage) && $this->inOwnTeam($actor, $role);
    }

    /** Always checked (SuperAdminGate::ALWAYS_CHECKED): system roles are never deleted, by anyone (W6, FU2-3). */
    public function delete(User $actor, RoleModel $role): bool
    {
        if ($this->isSystemRole($role)) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $actor->can(Permission::RolesManage) && $this->inOwnTeam($actor, $role);
    }

    /** The two system roles are the only roles without a team; a custom role always has one (ROLE-8). */
    private function isSystemRole(RoleModel $role): bool
    {
        return $role->getAttribute('team_id') === null;
    }

    private function inOwnTeam(User $actor, RoleModel $role): bool
    {
        $teamId = $role->getAttribute('team_id');

        return $teamId !== null && $actor->organisation_id !== null && (int) $teamId === $actor->organisation_id;
    }
}
