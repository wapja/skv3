<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\HerdSiteStep;

/** §5.4: with HTTPS chosen, `herd secured` lists the site. Herd's exit codes say nothing (§13), so its listing decides. */
final readonly class HerdSecuredCheck implements Check
{
    public function key(): string
    {
        return 'herd-secured';
    }

    public function label(): string
    {
        return __('HTTPS certificate');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->detection->herd) {
            return CheckResult::skipped(__('Herd is not installed'));
        }

        if ($context->answer('https') !== true) {
            return CheckResult::skipped(__('HTTPS was not chosen'));
        }

        $command = 'herd secure '.$context->folderName();

        if ($context->toolchain->herd() === null) {
            return CheckResult::fail(__('The herd command was not found.'), $command);
        }

        return new HerdSiteStep($context->toolchain)->securesProject($context->setupContext())
            ? CheckResult::pass(__('Herd secures this site'))
            : CheckResult::fail(__('Herd has not secured this site'), $command);
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
