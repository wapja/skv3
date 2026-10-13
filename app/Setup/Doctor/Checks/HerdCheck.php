<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: Herd is present. The kit works without it, so this only warns. */
final readonly class HerdCheck implements Check
{
    public function key(): string
    {
        return 'herd';
    }

    public function label(): string
    {
        return __('Herd');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->detection->herd) {
            return CheckResult::pass(__('Herd is installed'));
        }

        return CheckResult::warn(__('Herd is not installed'), 'https://herd.laravel.com');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
