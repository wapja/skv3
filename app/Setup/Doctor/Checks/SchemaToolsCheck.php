<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;

/** §5.4, §7.3: a committed PostgreSQL schema dump needs `psql` and `pg_dump`; without them, warn. */
final readonly class SchemaToolsCheck implements Check
{
    private PostgresBinaries $binaries;

    public function __construct(string $herdServices = PostgresBinaries::HERD_SERVICES)
    {
        $this->binaries = new PostgresBinaries($herdServices);
    }

    public function key(): string
    {
        return 'schema-tools';
    }

    public function label(): string
    {
        return __('Schema dump tools');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ((glob($context->path('database/schema').'/pgsql-schema.*') ?: []) === []) {
            return CheckResult::skipped(__('There is no PostgreSQL schema dump'));
        }

        $missing = array_values(array_filter(['psql', 'pg_dump'], fn (string $binary): bool => $this->binaries->find($context, $binary) === null));

        if ($missing === []) {
            return CheckResult::pass(__('psql and pg_dump are available for the schema dump'));
        }

        return CheckResult::warn(
            __('The schema dump needs :binaries, which were not found', ['binaries' => implode(', ', $missing)]),
            __('Remove database/schema/pgsql-schema.* (§7.3)'),
        );
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
