<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use LogicException;

/** §5.4, DEC-21: the global organisation_admin template role exists; permissions:sync recreates it. */
final readonly class OrganisationAdminRoleCheck implements Check
{
    public function key(): string
    {
        return 'organisation-admin-role';
    }

    public function label(): string
    {
        return __('Organisation admin role');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->databaseReachable()) {
            return CheckResult::skipped(__('The database is not reachable'));
        }

        try {
            Role::templateId();
        } catch (LogicException) {
            return CheckResult::fail(__('The organisation admin template role is missing'), 'php artisan permissions:sync');
        }

        return CheckResult::pass(__('The organisation admin template role exists'));
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
