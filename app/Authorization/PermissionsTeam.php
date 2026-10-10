<?php

declare(strict_types=1);

namespace App\Authorization;

use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * The only place that changes Spatie's team id (§6.3). Everything else uses run() /
 * withPermissionsTeam(), which restores the previous team in a finally block (W11).
 * After a switch, call $user->unsetRelation('roles') on models that may have loaded roles.
 */
final class PermissionsTeam
{
    public static function current(): ?int
    {
        $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

        return $teamId === null ? null : (int) $teamId;
    }

    /** Reserved for the TenantResolved / TenantForgotten listeners. */
    public static function set(?int $teamId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(?int $teamId, Closure $callback): mixed
    {
        $previous = self::current();
        self::set($teamId);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }
}
