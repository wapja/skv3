<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Support\Str;

/**
 * Host names and absolute URLs (§4.1, §6.2). Scheme and port come from the current request;
 * in the console and queue workers Laravel builds that request from APP_URL.
 */
final class Hosts
{
    public static function apex(): string
    {
        return Str::lower((string) config('tenancy.apex_domain'));
    }

    public static function admin(): string
    {
        return Str::lower((string) config('tenancy.admin_subdomain')).'.'.self::apex();
    }

    public static function tenant(string $slug): string
    {
        return Str::lower($slug).'.'.self::apex();
    }

    public static function origin(string $host): string
    {
        $request = request();
        $scheme = $request->getScheme();
        $port = (int) $request->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== 0 && $port !== $defaultPort ? ':'.$port : '');
    }

    public static function url(string $host, string $path = '/'): string
    {
        return self::origin($host).'/'.ltrim($path, '/');
    }
}
