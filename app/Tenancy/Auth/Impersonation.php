<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use Illuminate\Support\Facades\Session;

/**
 * Impersonation (§4.7, §6.8): an in-house, session-level identity swap on the stock session guard
 * (DEC-28, OD-1). This part answers whether the module is on and whether this session is impersonating;
 * the swap itself arrives with the stop route (Task 20).
 */
final class Impersonation
{
    /** The impersonator's id; its presence in the session is what "impersonating" means. */
    public const string SESSION_KEY = 'impersonation.impersonator_id';

    /** The module flag (§6.8): config('skv.modules.impersonation'), from MODULE_IMPERSONATION. */
    public static function enabled(): bool
    {
        return (bool) config('skv.modules.impersonation', false);
    }

    /**
     * Whether this session is impersonating. Deliberately independent of the flag: a module that is turned
     * off mid-impersonation must still be able to end it (RK-I3), and the audit causer rewrite still applies.
     */
    public static function isActive(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    public static function impersonatorId(): ?int
    {
        return self::idFrom(Session::get(self::SESSION_KEY));
    }

    private static function idFrom(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
