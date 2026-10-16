<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** §5.4, F2: the default queue connection resolves and, for the database driver, its jobs table exists; skipped while that table's connection is the default one and the database is down. */
final readonly class QueueCheck implements Check
{
    public function key(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return __('Queue');
    }

    public function run(DoctorContext $context): CheckResult
    {
        // In setup mode the request runs on the sync queue; the doctor checks the project's own connection (§5.3.4).
        $name = (string) (config('setup.replaced.queue') ?? config('queue.default'));

        try {
            Queue::connection($name);
        } catch (Throwable) {
            return CheckResult::fail(__('The queue connection :name does not work', ['name' => $name]), __('Check QUEUE_CONNECTION in .env'));
        }

        if (config("queue.connections.{$name}.driver") !== 'database') {
            return CheckResult::pass(__('The :name queue works', ['name' => $name]));
        }

        $table = (string) (config("queue.connections.{$name}.table") ?? 'jobs');
        $connection = config("queue.connections.{$name}.connection");
        $connection = is_string($connection) ? $connection : null;

        // F2: an outage is the database check's failure; a "missing table" with a migrate fix would mislead.
        if ($context->defaultConnectionDown($connection)) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        try {
            $exists = Schema::connection($connection)->hasTable($table);
        } catch (Throwable) {
            $exists = false;
        }

        return $exists
            ? CheckResult::pass(__('The :name queue works', ['name' => $name]))
            : CheckResult::fail(__('The :table table of the database queue is missing', ['table' => $table]), 'php artisan migrate');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
