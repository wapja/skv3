<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

/**
 * Finds a PostgreSQL client binary for the `pg-dump` and `schema-tools` checks (§5.4): PG_DUMP_BINARY_PATH from
 * `.env` first, then the toolchain PATH (DEC-23), then the newest of Herd's PostgreSQL services.
 */
final readonly class PostgresBinaries
{
    public const string HERD_SERVICES = '/Users/Shared/Herd/services/postgresql';

    /** @param  string  $herdServices  where Herd keeps its PostgreSQL versions; tests pass a sandbox directory */
    public function __construct(private string $herdServices = self::HERD_SERVICES) {}

    /** The absolute path of an executable $binary, or null. */
    public function find(DoctorContext $context, string $binary): ?string
    {
        $configured = $context->env()->get('PG_DUMP_BINARY_PATH') ?? '';
        $candidates = $configured !== '' ? [rtrim($configured, '/').'/'.$binary] : [];
        $candidates[] = $context->toolchain->which($binary);

        $versions = glob($this->herdServices.'/*/bin/'.$binary) ?: [];
        usort($versions, fn (string $a, string $b): int => version_compare(basename(dirname($b, 2)), basename(dirname($a, 2))));

        foreach ([...$candidates, ...$versions] as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
