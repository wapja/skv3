<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Str;

/** §5.4: `.env` has a HEALTH_CHECK_KEY; the fix writes a random 40-character one. The key is never shown. */
final readonly class HealthCheckKeyCheck implements Check
{
    public function key(): string
    {
        return 'health-check-key';
    }

    public function label(): string
    {
        return __('Health check key');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (($context->env()->get('HEALTH_CHECK_KEY') ?? '') !== '') {
            return CheckResult::pass(__('HEALTH_CHECK_KEY is set'));
        }

        return CheckResult::fail(__('HEALTH_CHECK_KEY is empty'), 'php artisan skv:doctor --fix');
    }

    /** Writes a key only when there is none, so a key a monitor already uses is never replaced. */
    public function fix(DoctorContext $context): CheckResult
    {
        if (($context->env()->get('HEALTH_CHECK_KEY') ?? '') === '') {
            $context->env()->set(['HEALTH_CHECK_KEY' => Str::random(40)]);
        }

        $context->forgetSync();

        return $this->run($context);
    }
}
