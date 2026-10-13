<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\DatabaseStep;
use Illuminate\Support\Facades\DB;

/** §5.4: on PostgreSQL, the `<db>_test` database that `composer test:pgsql` uses exists (setup step 2 creates it). */
final readonly class TestDatabaseCheck implements Check
{
    public function key(): string
    {
        return 'test-database';
    }

    public function label(): string
    {
        return __('Test database');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->env()->get('DB_CONNECTION') !== 'pgsql') {
            return CheckResult::skipped(__('The project does not use PostgreSQL'));
        }

        if (! $context->databaseReachable()) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return CheckResult::skipped(__('The database connection is not PostgreSQL'));
        }

        $database = ($context->env()->get('DB_DATABASE') ?: DatabaseStep::databaseName($context->setupContext())).'_test';

        if (DB::table('pg_database')->where('datname', $database)->exists()) {
            return CheckResult::pass(__('The test database :name exists', ['name' => $database]));
        }

        return CheckResult::fail(__('The test database :name is missing', ['name' => $database]), 'composer test:pgsql');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
