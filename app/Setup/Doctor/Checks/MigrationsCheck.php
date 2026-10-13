<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Database\Migrations\Migrator;

/**
 * §5.4: no pending migration, comparing the migrator's repository with the migration files of the running
 * application (its own database/migrations plus the paths packages register), as `migrate:status` does.
 */
final readonly class MigrationsCheck implements Check
{
    public function key(): string
    {
        return 'migrations';
    }

    public function label(): string
    {
        return __('Migrations');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->databaseReachable()) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        $migrator = app(Migrator::class);

        $files = array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $pending = count(array_diff($files, $ran));

        if ($pending === 0) {
            return CheckResult::pass(__('No pending migrations'));
        }

        return CheckResult::fail(__('Pending migrations: :count', ['count' => $pending]), 'php artisan migrate');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
