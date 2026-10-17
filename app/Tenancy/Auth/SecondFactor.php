<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\Organisation;
use App\Models\User;
use App\Support\Skv;

/**
 * The second factor (§6.6, §6.8, CD-4). Who has one: a confirmed TOTP secret, or, only while the passkeys module
 * is on, at least one passkey (with the module off a passkey signs nobody in, so it no longer counts either). Who
 * must have one: a super admin when config('skv.second_factor.super_admins') says so, whatever their
 * organisation_id; anyone else when their own organisation's require_second_factor is on. Both hold on every host.
 * EnsureSecondFactor enforces it; the security page and UpdateOrganisation read it. Fortify challenges only a TOTP
 * secret after the password, so a user the rule applies to whose only factor is a passkey signs in with the passkey:
 * AuthenticateOnThisHost refuses their password sign-in (needsPasskeySignIn(), DEC-35).
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

    public static function requiredFor(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return config('skv.second_factor.super_admins') === true;
        }

        if ($user->organisation_id === null) {
            return false;
        }

        // The organisations table is central: no tenant scope, so this reads the same row on every host. An archived
        // organisation (soft-deleted) has no members who can sign in, and is read as no rule.
        return Organisation::query()->whereKey($user->organisation_id)->value('require_second_factor') === true;
    }

    /** The rule applies to $user and they have not met it yet. */
    public static function missingFor(User $user): bool
    {
        return self::requiredFor($user) && ! self::satisfiedBy($user);
    }

    /**
     * The rule applies to $user and only a passkey meets it: the password alone would sign them in without any second
     * factor, so they sign in with the passkey. A user without any factor yet still signs in with the password and is
     * then sent to set one up; with a confirmed TOTP secret Fortify asks for the code.
     */
    public static function needsPasskeySignIn(User $user): bool
    {
        return ! $user->hasEnabledTwoFactorAuthentication() && self::requiredFor($user) && self::satisfiedBy($user);
    }

    /**
     * What the user must do, for the redirect's flash and the security page's callout. With the passkeys module
     * off only two-factor authentication is offered (§6.8).
     */
    public static function explanation(User $user): string
    {
        $passkeys = Skv::passkeysEnabled();

        if ($user->isSuperAdmin()) {
            return $passkeys
                ? __('Super admins need a second factor. Set up two-factor authentication or a passkey to continue.')
                : __('Super admins need a second factor. Set up two-factor authentication to continue.');
        }

        return $passkeys
            ? __('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.')
            : __('Your organisation requires a second factor. Set up two-factor authentication to continue.');
    }
}
