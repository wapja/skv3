<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\EffectivePermissions;
use App\Authorization\Permission;
use App\Authorization\UserAbilities;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;

/**
 * Invitation abilities (§4.5). None of the names is in SuperAdminGate::ALWAYS_CHECKED, so super
 * admins pass through Gate::before (FU2-3).
 */
final readonly class InvitationPolicy
{
    public function __construct(
        private EffectivePermissions $effectivePermissions,
        private UserAbilities $userAbilities,
    ) {}

    /** Roles attach at invite time, so inviting needs users.create and roles.assign in the own organisation (§4.6, OR-M7). */
    public function create(User $actor, Organisation $organisation): bool
    {
        return $actor->can(Permission::UsersCreate)
            && $actor->can(Permission::RolesAssign)
            && $actor->organisation_id !== null
            && $actor->organisation_id === $organisation->id;
    }

    public function remind(User $actor, Invitation $invitation): bool
    {
        return $actor->can(Permission::UsersInviteResend) && $this->sameOrganisation($actor, $invitation);
    }

    public function cancel(User $actor, Invitation $invitation): bool
    {
        return $actor->can(Permission::UsersDelete) && $this->sameOrganisation($actor, $invitation);
    }

    /**
     * The accept link is a bearer credential for the invitation's roles (RK-B3): only someone who
     * could grant them sees it — the remind conditions, roles.assign and the pending user's effective
     * permissions being a subset of the actor's (RL-26).
     */
    public function viewLink(User $actor, Invitation $invitation): bool
    {
        if (! $this->remind($actor, $invitation) || ! $actor->can(Permission::RolesAssign)) {
            return false;
        }

        return $this->userAbilities->covers(
            $this->effectivePermissions->of($actor, $invitation->organisation_id),
            $this->effectivePermissions->of($invitation->user, $invitation->organisation_id),
        );
    }

    /**
     * The actor belongs to the invitation's organisation. Organisation members never act on a pending
     * super admin (§6.3), mirroring UserAbilities::manages(): the super_admin badge has no team, so
     * EffectivePermissions leaves it out and viewLink's subset check alone would hand out a platform
     * account's accept link. The organisation is checked first: on another tenant's host the scoped
     * user relation resolves to nothing. Super admins pass through Gate::before.
     */
    private function sameOrganisation(User $actor, Invitation $invitation): bool
    {
        return $actor->organisation_id !== null
            && $actor->organisation_id === $invitation->organisation_id
            && ! $invitation->user->isSuperAdmin();
    }
}
