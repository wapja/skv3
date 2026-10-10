<?php

declare(strict_types=1);

use App\Authorization\PermissionsTeam;

if (! function_exists('withPermissionsTeam')) {
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    function withPermissionsTeam(?int $teamId, Closure $callback): mixed
    {
        return PermissionsTeam::run($teamId, $callback);
    }
}
