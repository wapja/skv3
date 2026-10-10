<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can(Permission::UsersView) && $this->managesMember($actor, $target);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(Permission::UsersUpdate) && $this->managesMember($actor, $target);
    }

    /** Always checked: nobody deletes themselves, super admins included (W2). */
    public function delete(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return ! $target->isSuperAdmin()
            && $actor->can(Permission::UsersDelete)
            && $this->sameOrganisation($actor, $target);
    }

    /** Always checked: nobody disables themselves (W12). */
    public function updateStatus(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return ! $target->isSuperAdmin()
            && $actor->can(Permission::UsersUpdate)
            && $this->sameOrganisation($actor, $target);
    }

    /** Always checked: roles belong to an organisation; super admins carry only the badge (W1). */
    public function updateRoles(User $actor, User $target): bool
    {
        if ($target->isSuperAdmin() || $target->organisation_id === null) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $actor->can(Permission::RolesAssign) && $this->sameOrganisation($actor, $target);
    }

    /** Always checked: only another super admin changes the flag (§6.3). */
    public function updateSuperAdmin(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() && $actor->isNot($target);
    }

    /** Organisation admins never act on super admins, even promoted ones that keep an organisation_id (§6.3). */
    private function managesMember(User $actor, User $target): bool
    {
        return ! $target->isSuperAdmin() && $this->sameOrganisation($actor, $target);
    }

    private function sameOrganisation(User $actor, User $target): bool
    {
        return $actor->organisation_id !== null && $actor->organisation_id === $target->organisation_id;
    }
}
