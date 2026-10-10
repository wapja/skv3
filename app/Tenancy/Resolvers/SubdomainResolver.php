<?php

declare(strict_types=1);

namespace App\Tenancy\Resolvers;

use App\Models\Organisation;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Hosts;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * {slug}.{apex} → organisation (§6.2). Only one label of 3–63 characters [a-z0-9-] without
 * a leading or trailing hyphen; reserved slugs and the admin subdomain are never tenants.
 * The cache stores ids only (cache.serializable_classes is false).
 */
final readonly class SubdomainResolver implements TenantResolver
{
    public function __construct(private Cache $cache) {}

    public static function cacheKey(string $slug): string
    {
        return 'tenancy.slug.'.$slug;
    }

    public function resolve(Request $request): ?Organisation
    {
        $host = Str::lower($request->getHost());

        if ($host === Hosts::apex() || $host === Hosts::admin()) {
            return null;
        }

        $slug = $this->slugFrom($host) ?? throw new TenantNotFoundException;

        // Verify the cached id still carries this slug, and never cache misses (no cache write per
        // random subdomain).
        $key = self::cacheKey($slug);
        $id = $this->cache->get($key);
        $organisation = is_int($id) ? Organisation::query()->find($id) : null;

        if ($organisation === null || $organisation->slug !== $slug) {
            $this->cache->forget($key);
            $organisation = Organisation::query()->where('slug', $slug)->first() ?? throw new TenantNotFoundException;
            $this->cache->put($key, $organisation->id, (int) config('tenancy.slug_cache_ttl'));
        }

        return $organisation;
    }

    private function slugFrom(string $host): ?string
    {
        $suffix = '.'.Hosts::apex();

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $slug) !== 1) {
            return null;
        }

        /** @var list<string> $reserved */
        $reserved = config('tenancy.reserved_slugs', []);

        return in_array($slug, $reserved, true) ? null : $slug;
    }
}
