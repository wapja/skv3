<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role as RoleModel;

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

    /**
     * Whether a custom role name would pass for a system role (§4.6, FU2-4, RL-19): trimmed, lower-cased,
     * runs of spaces and hyphens folded to "_", then compared with the system role names.
     * "Organisation_Admin", " super admin " and "organisation-admin" are all reserved.
     */
    public static function isReservedName(string $name): bool
    {
        $folded = Str::of($name)->trim()->lower()->replaceMatches('/[\s-]+/', '_')->toString();

        return in_array($folded, self::values(), true);
    }

    /**
     * The id of the global organisation_admin template role (team_id NULL, DEC-21). Callers match the
     * template by this id, never by name: a team role may share the name (FU2-4).
     */
    public static function templateId(): int
    {
        $id = RoleModel::query()
            ->whereNull('team_id')
            ->where('name', self::OrganisationAdmin->value)
            ->where('guard_name', SyncPermissions::GUARD)
            ->value('id');

        return $id === null
            ? throw new LogicException('The organisation_admin template role is missing; run permissions:sync.')
            : (int) $id;
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Super admin'),
            self::OrganisationAdmin => __('Organisation admin'),
        };
    }
}
