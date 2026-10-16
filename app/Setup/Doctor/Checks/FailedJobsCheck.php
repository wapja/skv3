<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * §4.9, CD-3: failed jobs. Fails only when the database failer's table is missing; stored failed jobs warn (the
 * schedule prunes them daily), so they never turn /health-check into a 503. Skipped while the failer's connection is
 * the default one and the database is down (F2): the database check reports the outage.
 */
final readonly class FailedJobsCheck implements Check
{
    public function key(): string
    {
        return 'failed-jobs';
    }

    public function label(): string
    {
        return __('Failed jobs');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $driver = (string) config('queue.failed.driver', 'null');

        if ($driver === 'null') {
            return CheckResult::pass(__('Failed jobs are not stored'));
        }

        if (in_array($driver, ['database', 'database-uuids'], true)) {
            $table = (string) config('queue.failed.table', 'failed_jobs');
            $connection = config('queue.failed.database');
            $connection = is_string($connection) ? $connection : null;

            if ($context->defaultConnectionDown($connection)) {
                return CheckResult::skipped(__('The database is not reachable'));
            }

            try {
                $exists = Schema::connection($connection)->hasTable($table);
            } catch (Throwable) {
                $exists = false;
            }

            if (! $exists) {
                return CheckResult::fail(__('The :table table is missing', ['table' => $table]), 'php artisan migrate');
            }
        }

        $count = $this->storedFailedJobs(app(FailedJobProviderInterface::class));

        return $count === 0
            ? CheckResult::pass(__('No failed jobs'))
            : CheckResult::warn(trans_choice(':count failed job is stored|:count failed jobs are stored', $count), 'php artisan queue:retry all');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }

    /**
     * The number of stored failed jobs. Typed as the contract: Larastan resolves the container binding to the
     * database provider and would flag the instanceof as always true; drivers without count() fall back to all().
     */
    private function storedFailedJobs(FailedJobProviderInterface $failer): int
    {
        return $failer instanceof CountableFailedJobProvider ? $failer->count() : count($failer->all());
    }
}
