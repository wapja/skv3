<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\UserAbilities;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;

/**
 * §4.4, §4.6. view, update, updateStatus, delete and restore are decided by UserAbilities, the same
 * code the users index evaluates for a page (RL-26). restore is not always-checked: super admins pass
 * Gate::before and RestoreUser applies the domain guards (archived organisation, taken address).
 */
final readonly class UserPolicy
{
    public function __construct(private UserAbilities $abilities) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->abilities->forTarget($actor, $target)['view'];
    }

    public function update(User $actor, User $target): bool
    {
        return $this->abilities->forTarget($actor, $target)['update'];
    }

    /** Always checked: nobody deletes themselves, super admins included (W2). */
    public function delete(User $actor, User $target): bool
    {
        return $this->abilities->forTarget($actor, $target)['delete'];
    }

    /** Always checked: nobody disables themselves (W12). */
    public function updateStatus(User $actor, User $target): bool
    {
        return $this->abilities->forTarget($actor, $target)['updateStatus'];
    }

    /** users.delete + same organisation + the permission-subset rule (DEC-26 (11)). */
    public function restore(User $actor, User $target): bool
    {
        return $this->abilities->forTarget($actor, $target)['restore'];
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

        return $actor->can(Permission::RolesAssign)
            && $actor->organisation_id !== null
            && $actor->organisation_id === $target->organisation_id;
    }

    /** Always checked: only another super admin changes the flag (§6.3). */
    public function updateSuperAdmin(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() && $actor->isNot($target);
    }

    /**
     * Always checked (§4.7, RL-20): the module is on, this session is not impersonating already (depth 1)
     * and the impersonation matrix allows the pair. Super admins are granted inside the matrix, because
     * Gate::before skips this ability (FU2-2).
     */
    public function impersonate(User $actor, User $target): bool
    {
        return Impersonation::enabled()
            && ! Impersonation::isActive()
            && $this->abilities->mayImpersonate($actor, $target);
    }
}
