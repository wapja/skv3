<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * System roles (§4.6, §6.3). super_admin is a display badge only: it carries no permissions
 * and is never assignable through UpdateUserRoles; users.is_super_admin grants the access.
 * organisation_admin is one global template role (team_id NULL) attached per team (DEC-21).
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case OrganisationAdmin = 'organisation_admin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Super admin'),
            self::OrganisationAdmin => __('Organisation admin'),
        };
    }
}
