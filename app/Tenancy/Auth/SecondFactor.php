<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;
use App\Support\Skv;

/**
 * The second factor (§6.6, §6.8): a confirmed TOTP secret, or, only while the passkeys module is on, at least
 * one passkey. With the module off a passkey no longer signs anyone in, so it no longer counts either.
 */
final class SecondFactor
{
    public static function satisfiedBy(User $user): bool
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        return Skv::passkeysEnabled() && $user->passkeys()->exists();
    }
}
