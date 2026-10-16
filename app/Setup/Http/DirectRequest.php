<?php

declare(strict_types=1);

namespace App\Setup\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A request straight from the local machine (§5.3.4, DEC-24, RL-4): the local environment, a loopback REMOTE_ADDR
 * (127.0.0.0/8 or ::1) read raw, never through the trusted proxies, and none of the headers a proxy or a tunnel adds:
 * no X-Forwarded-* of any kind, no X-Expose-*, no Forwarded and no X-Original-Host. The address alone is not enough,
 * because `herd share` traffic arrives from loopback too; the headers alone are not enough, because `php artisan
 * serve --host=0.0.0.0` lets the LAN in without any (g4-01).
 */
final class DirectRequest
{
    private const array LOOPBACK = ['127.0.0.0/8', '::1'];

    private const array HEADERS = ['forwarded', 'x-original-host'];

    private const array PREFIXES = ['x-forwarded-', 'x-expose-'];

    public static function isDirect(Request $request): bool
    {
        if (! app()->isLocal()) {
            return false;
        }

        $address = $request->server('REMOTE_ADDR');

        if (! is_string($address) || ! IpUtils::checkIp($address, self::LOOPBACK)) {
            return false;
        }

        return array_all(array_keys($request->headers->all()), fn (string $name): bool => ! in_array($name, self::HEADERS, true)
            && ! array_any(self::PREFIXES, fn (string $prefix): bool => str_starts_with($name, $prefix)));
    }
}
