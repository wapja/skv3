<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: `.env` has an APP_KEY. The key itself is never shown. */
final readonly class AppKeyCheck implements Check
{
    public function key(): string
    {
        return 'app-key';
    }

    public function label(): string
    {
        return __('Application key');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (($context->env()->get('APP_KEY') ?? '') !== '') {
            return CheckResult::pass(__('APP_KEY is set'));
        }

        return CheckResult::fail(__('APP_KEY is empty'), 'php artisan key:generate');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
