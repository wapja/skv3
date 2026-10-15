<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttles /health-check per address on the ops cache store (CD-5), not the default database store the
 * `throttle` middleware uses, so a database outage is reported as a 503 instead of failing the limiter.
 */
final readonly class ThrottleHealthChecks
{
    public const int PER_MINUTE = 60;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = new RateLimiter(Cache::store((string) config('skv.ops_cache_store', 'file')));
        $key = 'health-check:'.$request->ip();

        if ($limiter->tooManyAttempts($key, self::PER_MINUTE)) {
            return new JsonResponse(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => (string) $limiter->availableIn($key)]);
        }

        $limiter->hit($key, 60);

        return $next($request);
    }
}
