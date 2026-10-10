<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\Organisation;
use App\Models\User;

/** §4.3: view/update = permission + ownership; create/delete/restore/forceDelete = super admin. */
final class OrganisationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::OrganisationsView);
    }

    public function view(User $actor, Organisation $organisation): bool
    {
        return $actor->can(Permission::OrganisationsView) && $actor->organisation_id === $organisation->id;
    }

    /** Super admins pass through Gate::before. */
    public function create(): bool
    {
        return false;
    }

    public function update(User $actor, Organisation $organisation): bool
    {
        return $actor->can(Permission::OrganisationsUpdate) && $actor->organisation_id === $organisation->id;
    }

    /** Always checked: archiving applies to active organisations only. */
    public function delete(User $actor, Organisation $organisation): bool
    {
        return $actor->isSuperAdmin() && ! $organisation->trashed();
    }

    /** Super admins pass through Gate::before. */
    public function restore(): bool
    {
        return false;
    }

    /** Always checked: purge is only available for archived organisations. */
    public function forceDelete(User $actor, Organisation $organisation): bool
    {
        return $actor->isSuperAdmin() && $organisation->trashed();
    }
}
