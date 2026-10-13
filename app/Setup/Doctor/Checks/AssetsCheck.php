<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\FrontendStep;

/** §5.4: the Vite manifest exists and is current (setup step 5's rule); the fix runs that step's build. */
final readonly class AssetsCheck implements Check
{
    public function key(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return __('Assets');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (FrontendStep::manifestIsCurrent($context->basePath)) {
            return CheckResult::pass(__('The Vite build is current'));
        }

        return CheckResult::fail(__('The Vite build is missing or out of date'), 'npm ci && npm run build');
    }

    /** `npm ci && npm run build` through the frontend step, which checks Node first; Playwright is left alone. */
    public function fix(DoctorContext $context): CheckResult
    {
        new FrontendStep($context->toolchain)->run($context->setupContext());
        $context->forgetSync();

        return $this->run($context);
    }
}
