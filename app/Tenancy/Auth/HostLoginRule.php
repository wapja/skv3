<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;

/**
 * Who may sign in on the current host (§4.2): an active, non-deleted user who belongs to it.
 * Super admins belong to every host. Password, two-factor and passkey sign-in all use this.
 */
final class HostLoginRule
{
    public function allows(User $user): bool
    {
        return ! $user->trashed() && $user->isActive() && $user->belongsToCurrentHost();
    }
}
