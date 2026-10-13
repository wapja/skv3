<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: Herd Pro (its mail and database services); only warns. */
final readonly class HerdProCheck implements Check
{
    public function key(): string
    {
        return 'herd-pro';
    }

    public function label(): string
    {
        return __('Herd Pro');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->detection->herd) {
            return CheckResult::skipped(__('Herd is not installed'));
        }

        return $context->detection->herdPro
            ? CheckResult::pass(__('Herd Pro is active'))
            : CheckResult::warn(__('Herd Pro was not detected'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
