<?php

declare(strict_types=1);

namespace App\Support\RateLimits;

/**
 * The cache key under which ThrottleRequests counts one Limit of a named limiter: md5(limiter name . limit key)
 * (Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter()). md5 is Laravel's storage
 * format here, not a security hash, so tests/Arch/PresetsTest.php exempts this class from the security preset.
 * Code that clears a named limiter's bucket outside the middleware (G1-03) builds the key here, nowhere else.
 */
final class ThrottleKey
{
    public static function of(string $limiter, string $key): string
    {
        return md5($limiter.$key);
    }
}
