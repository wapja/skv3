<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;

/**
 * CD-10: a test that needs PostgreSQL, or a pg_dump that can dump it, skips only where nobody asked for PostgreSQL.
 * CI sets REQUIRE_POSTGRES=true on the main (PostgreSQL) check row and in the browser job, so a broken service or
 * client fails there instead of hiding the test (F16, F17). Locally, and on the SQLite rows, the flag is unset.
 */
final class RequiredPostgres
{
    public const string FLAG = 'REQUIRE_POSTGRES';

    /**
     * Whether the test may skip: never while the flag is `true`, else when $available says no. $available runs only
     * when the flag is unset, so a required run always reaches the test body and fails there.
     *
     * @param  Closure(): bool  $available
     */
    public static function skips(Closure $available): bool
    {
        return getenv(self::FLAG) !== 'true' && ! $available();
    }
}
