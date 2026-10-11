<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;
use Illuminate\Support\Str;
use LogicException;

/**
 * Host names and absolute URLs (§4.1, §6.2). Scheme and port come from the current request;
 * in the console and queue workers Laravel builds that request from APP_URL.
 */
final class Hosts
{
    /** One DNS label of 3–63 characters [a-z0-9-] without a leading or trailing hyphen (§4.3, §6.2). */
    public const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/';

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

    /** The host a user signs in on: the admin host for super admins, their organisation's host otherwise. */
    public static function homeOf(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return self::admin();
        }

        $organisation = $user->organisation ?? throw new LogicException("User [{$user->id}] has no organisation.");

        return self::tenant($organisation->slug);
    }

    /** Reserved words and the configured admin subdomain are never organisation slugs (§4.3, FU2-1, FU3-5). */
    public static function isReservedSlug(string $slug): bool
    {
        /** @var list<string> $reserved */
        $reserved = config('tenancy.reserved_slugs', []);

        return in_array($slug, [...$reserved, Str::lower((string) config('tenancy.admin_subdomain'))], true);
    }

    /**
     * The organisation slug a host names, or null when it is not {slug}.{apex} with a valid,
     * unreserved slug (§6.2, SLUG_PATTERN; the admin subdomain counts as reserved, FU3-5).
     * Expects a lower-cased host. Whether the organisation exists is not checked.
     */
    public static function slugOf(string $host): ?string
    {
        $suffix = '.'.self::apex();

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || self::isReservedSlug($slug)) {
            return null;
        }

        return $slug;
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
