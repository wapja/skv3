<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: when Playwright was chosen, it is installed (`composer test:browser` needs it). */
final readonly class PlaywrightCheck implements Check
{
    public function key(): string
    {
        return 'playwright';
    }

    public function label(): string
    {
        return __('Playwright');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->answer('playwright') !== true) {
            return CheckResult::skipped(__('Playwright was not chosen'));
        }

        if (is_file($context->path('node_modules/.bin/playwright'))) {
            return CheckResult::pass(__('Playwright is installed'));
        }

        return CheckResult::warn(__('Playwright is not installed'), 'npx playwright install chromium');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
