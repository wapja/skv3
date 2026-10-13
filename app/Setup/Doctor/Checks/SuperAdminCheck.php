<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Enums\UserStatus;
use App\Models\User;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Tenancy\Facades\Tenant;

/** §5.4: at least one active super admin, or nobody can administer the platform. */
final readonly class SuperAdminCheck implements Check
{
    public function key(): string
    {
        return 'super-admin';
    }

    public function label(): string
    {
        return __('Super admin');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->databaseReachable()) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        $count = Tenant::bypass(fn (): int => User::query()->where('is_super_admin', true)->where('status', UserStatus::Active)->count());

        if ($count > 0) {
            return CheckResult::pass(__('Active super admins: :count', ['count' => $count]));
        }

        return CheckResult::fail(__('There is no active super admin'), 'php artisan skv:create-super-admin');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
