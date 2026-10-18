<?php

declare(strict_types=1);

namespace App\Setup\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A request straight from the local machine (§5.3.4, DEC-24, RL-4): the local environment, a loopback REMOTE_ADDR
 * (127.0.0.0/8 or ::1) read raw, never through the trusted proxies, and none of the headers a proxy or a tunnel adds:
 * no X-Forwarded-*, X-Expose-*, CF-* or Tailscale-* of any kind, no Forwarded, Via, X-Original-Host, X-Real-IP or
 * True-Client-IP (L7b-14: cloudflared and other tunnels strip X-Forwarded-* but add these), and no client-address or
 * original-URL header of other proxies and CDNs: X-Client-IP, Client-IP, X-Cluster-Client-IP, Fastly-Client-IP,
 * X-Host, X-Original-URL, a bare X-Forwarded or Forwarded-For (M7c T10 #5). The address alone is not enough,
 * because `herd share` traffic arrives from loopback too; the headers alone are not enough, because `php artisan
 * serve --host=0.0.0.0` lets the LAN in without any (g4-01). The headers are a deny-list: a tunnel that adds none of
 * them (ssh -R, socat) stays indistinguishable from the browser on this machine, so the manuals warn against tunnels
 * (DEC-33).
 */
final class DirectRequest
{
    private const array LOOPBACK = ['127.0.0.0/8', '::1'];

    private const array HEADERS = [
        'client-ip', 'fastly-client-ip', 'forwarded', 'forwarded-for', 'true-client-ip', 'via', 'x-client-ip',
        'x-cluster-client-ip', 'x-forwarded', 'x-host', 'x-original-host', 'x-original-url', 'x-real-ip',
    ];

    private const array PREFIXES = ['cf-', 'tailscale-', 'x-expose-', 'x-forwarded-'];

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
