<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Health\HealthCheckKey;
use App\Support\Health\OpsCache;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Throttles /health-check on the ops store (OpsCache), not the default database store the `throttle` middleware uses,
 * so a database outage is reported as a 503 instead of failing the limiter. The key decides the bucket (CD-4):
 * requests with the configured key count per key, so strangers never lock the monitor out; all other requests share
 * one bucket, so key-less traffic leaves one cache entry instead of one per address. Because the key picks the bucket
 * before any limit applies, the throttle does not slow down guessing the key (L7b-56): only the key's randomness does,
 * so a hand-set HEALTH_CHECK_KEY must be random (setup and skv:doctor --fix generate one).
 *
 * A broken ops store (an unknown OPS_CACHE_STORE, redis down, a full disk) lets the request through, so it never
 * locks monitoring out; the controller then answers a key holder 503 with JSON, never a 500, and reports the error.
 * The throttle itself logs one warning line with the exception's class only (L7b-58): no trace and no message, which
 * could hold the cache key, so key-less requests cannot flood the log.
 */
final readonly class ThrottleHealthChecks
{
    /** Requests a minute with the configured key, counted per key. */
    public const int PER_MINUTE = 60;

    /** Requests a minute without a matching key, counted together. */
    public const int DENIED_PER_MINUTE = 300;

    public const string DENIED_BUCKET = 'health-check:denied';

    /**
     * Followed by an HMAC-SHA-256 of the key under APP_KEY (L7b-57): neither the key nor a hash anyone can test guesses
     * against offline becomes a cache key.
     */
    public const string KEY_BUCKET_PREFIX = 'health-check:ok:';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $limiter = new RateLimiter(OpsCache::store());
            $key = HealthCheckKey::configured();
            [$bucket, $limit] = $key !== null && HealthCheckKey::matches($request->header(HealthCheckKey::HEADER))
                ? [self::KEY_BUCKET_PREFIX.hash_hmac('sha256', $key, (string) config('app.key')), self::PER_MINUTE]
                : [self::DENIED_BUCKET, self::DENIED_PER_MINUTE];

            if ($limiter->tooManyAttempts($bucket, $limit)) {
                return new JsonResponse(['message' => 'Too Many Attempts.'], 429, [
                    'Retry-After' => (string) $limiter->availableIn($bucket),
                    'Cache-Control' => 'no-store',
                ]);
            }

            $limiter->hit($bucket, 60);
        } catch (Throwable $exception) {
            Log::warning('The /health-check throttle is off: the ops cache store failed ('.$exception::class.').');
        }

        return $next($request);
    }
}
