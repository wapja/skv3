<?php

declare(strict_types=1);

namespace App\Setup\Http;

use Illuminate\Http\Request;

/**
 * A request straight from the local machine (§5.3.4, DEC-24): the local environment and none of the headers a proxy
 * or a tunnel such as `herd share` adds. The IP is no signal under Herd: everything arrives from 127.0.0.1.
 */
final class DirectRequest
{
    private const array FORWARDED = ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-original-host', 'forwarded'];

    public static function isDirect(Request $request): bool
    {
        if (! app()->isLocal()) {
            return false;
        }

        return array_all(array_keys($request->headers->all()), fn (string $name): bool => ! in_array($name, self::FORWARDED, true) && ! str_starts_with($name, 'x-expose-'));
    }
}
