<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use App\Support\ProcessAccount;
use App\Support\Skv;

/**
 * §5.4, RL-11: the backups module (M7) needs pg_dump; skipped while the module is off. Fails without ext-posix (CD-9):
 * backup:run then cannot tell this account's directories and refuses to start, whichever pg_dump it would find.
 */
final readonly class PgDumpCheck implements Check
{
    private PostgresBinaries $binaries;

    public function __construct(string $herdServices = PostgresBinaries::HERD_SERVICES)
    {
        $this->binaries = new PostgresBinaries($herdServices);
    }

    public function key(): string
    {
        return 'pg-dump';
    }

    public function label(): string
    {
        return __('pg_dump');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! Skv::moduleEnabled('backups')) {
            return CheckResult::skipped(__('The backups module is off'));
        }

        if (app(ProcessAccount::class)->uid() === null) {
            return CheckResult::fail(__('PHP has no posix extension, so backup:run cannot tell this account\'s directories and refuses to start'), __('Install the posix extension'));
        }

        $path = $this->binaries->find($context, 'pg_dump');

        if ($path !== null) {
            return CheckResult::pass(__('pg_dump found at :path', ['path' => $path]));
        }

        return CheckResult::fail(__('pg_dump was not found'), 'PG_DUMP_BINARY_PATH=/path/to/postgresql/bin');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
