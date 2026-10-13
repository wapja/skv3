<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Authorization\SyncPermissions;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/**
 * §5.4, DEC-28, FU2-13: the super_admin badge mirrors users.is_super_admin and carries no permissions. The dry-run
 * sync counts what permissions:sync would repair; the fix runs it.
 */
final readonly class SuperAdminBadgesCheck implements Check
{
    public function key(): string
    {
        return 'super-admin-badges';
    }

    public function label(): string
    {
        return __('Super admin badges');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->databaseReachable()) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        $sync = $context->sync();

        if ($sync->badgesRepaired === 0 && $sync->badgePermissionsStripped === 0) {
            return CheckResult::pass(__('The super admin badges match is_super_admin'));
        }

        return CheckResult::fail(__('The super admin badges have drifted from is_super_admin'), 'php artisan permissions:sync');
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
