<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Authorization\SyncPermissions;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4, DEC-26: the permission catalogue and the system roles are in sync; the fix runs permissions:sync. */
final readonly class PermissionsCheck implements Check
{
    public function key(): string
    {
        return 'permissions';
    }

    public function label(): string
    {
        return __('Permissions');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->databaseReachable()) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        return $context->sync()->changed()
            ? CheckResult::fail(__('The permissions are out of sync'), 'php artisan permissions:sync')
            : CheckResult::pass(__('The permissions are in sync'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        if (! $context->databaseReachable()) {
            return null;
        }

        app(SyncPermissions::class)->handle();
        $context->forgetSync();

        return $this->run($context);
    }
}
