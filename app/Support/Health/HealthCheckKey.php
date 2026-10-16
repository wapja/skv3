<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The /health-check key rule (CD-4, F15): HEALTH_CHECK_KEY counts only as a string of at least MIN_LENGTH bytes
 * (Str::random() writes one byte per character). A shorter one counts as not configured: the endpoint answers 403
 * and the doctor's health-check-key check fails until skv:doctor --fix replaces it. env() casts HEALTH_CHECK_KEY=true
 * to bool true, which is no key either.
 */
final readonly class HealthCheckKey
{
    public const int MIN_LENGTH = 32;

    public const string HEADER = 'X-Health-Check-Key';

    /** @phpstan-assert-if-true =string $key */
    public static function isUsable(mixed $key): bool
    {
        return is_string($key) && strlen($key) >= self::MIN_LENGTH;
    }

    /** The configured key, or null while it is unset, blank, not a string or shorter than MIN_LENGTH. */
    public static function configured(): ?string
    {
        $key = config('skv.health_check.key');

        return self::isUsable($key) ? $key : null;
    }

    /** Whether $given is the configured key, compared with hash_equals(); never true without a usable configured key. */
    public static function matches(mixed $given): bool
    {
        $key = self::configured();

        return $key !== null && is_string($given) && hash_equals($key, $given);
    }
}
