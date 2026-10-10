<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;

/**
 * Gate::before for super admins (§4.6). users.is_super_admin is the only source (W8). The
 * always-checked abilities return null so their policies and guards run for super admins too
 * (W2, W3, W12); those policies grant super admins explicitly where appropriate.
 */
final class SuperAdminGate
{
    /** @var list<string> */
    public const array ALWAYS_CHECKED = ['impersonate', 'delete', 'forceDelete', 'updateStatus', 'updateRoles', 'updateSuperAdmin'];

    public static function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::ALWAYS_CHECKED, true)) {
            return null;
        }

        return $user->isSuperAdmin() ? true : null;
    }
}
