<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Support\Skv;

/**
 * §5.4, RL-12: with Flux Pro installed, Composer has credentials for composer.fluxui.dev. `composer config` runs in the
 * base path, so it reads the project auth.json and falls back to the global one. Its output is the credentials
 * themselves: it is only tested for being non-empty, never shown or logged.
 */
final readonly class FluxProCredentialsCheck implements Check
{
    public const string ONE_LINER = 'composer config --global http-basic.composer.fluxui.dev <email> <license-key>';

    public function key(): string
    {
        return 'flux-pro-credentials';
    }

    public function label(): string
    {
        return __('Flux Pro credentials');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! Skv::hasFluxPro()) {
            return CheckResult::skipped(__('Flux Pro is not installed'));
        }

        $composer = $context->toolchain->which('composer') ?? 'composer';
        $result = $context->process(30)->run([$composer, 'config', 'http-basic.composer.fluxui.dev']);

        if ($result->successful() && trim($result->output()) !== '') {
            return CheckResult::pass(__('Composer has Flux Pro credentials'));
        }

        return CheckResult::fail(__('Composer has no Flux Pro credentials'), self::ONE_LINER);
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
