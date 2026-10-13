<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Facades\DB;

/** §5.4: the default connection opens. The driver's error is not shown: it can name hosts and users. */
final readonly class DatabaseCheck implements Check
{
    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return __('Database');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->databaseReachable()) {
            return CheckResult::pass(__('The database is reachable (:connection)', ['connection' => DB::getDefaultConnection()]));
        }

        return CheckResult::fail(__('The database is not reachable'), __('Check DB_* in .env'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
