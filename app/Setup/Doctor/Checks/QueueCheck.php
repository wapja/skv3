<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** §5.4: the default queue connection resolves and, for the database driver, its jobs table exists. */
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

        try {
            $exists = Schema::connection(is_string($connection) ? $connection : null)->hasTable($table);
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
