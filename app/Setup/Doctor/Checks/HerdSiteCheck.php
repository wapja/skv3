<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\HerdSiteStep;

/** §5.4: Herd serves the site, parked under a `herd paths` entry or in `herd links` (setup step 10's own rule). */
final readonly class HerdSiteCheck implements Check
{
    public function key(): string
    {
        return 'herd-site';
    }

    public function label(): string
    {
        return __('Herd site');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->detection->herd) {
            return CheckResult::skipped(__('Herd is not installed'));
        }

        $command = 'herd link '.$context->folderName();

        if ($context->toolchain->herd() === null) {
            return CheckResult::fail(__('The herd command was not found.'), $command);
        }

        return new HerdSiteStep($context->toolchain)->servesProject($context->setupContext())
            ? CheckResult::pass(__('Herd serves this site'))
            : CheckResult::fail(__('Herd does not serve this site'), $command);
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
