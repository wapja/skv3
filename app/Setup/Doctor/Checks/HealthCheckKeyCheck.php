<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\EnvironmentStep;
use App\Support\Health\HealthCheckKey;
use Illuminate\Support\Str;

/**
 * §5.4, CD-4, CD-5: HEALTH_CHECK_KEY is a usable key of at least HealthCheckKey::MIN_LENGTH characters, because
 * /health-check refuses a shorter one. A runtime context (the System page) judges the running configuration;
 * skv:doctor and setup judge .env. The fix writes a random key of EnvironmentStep::GENERATED's length. The key is
 * never shown.
 */
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
        $key = $context->setting('HEALTH_CHECK_KEY', 'skv.health_check.key');

        if ($key === null) {
            return CheckResult::fail(__('HEALTH_CHECK_KEY is empty'), 'php artisan skv:doctor --fix');
        }

        if (! HealthCheckKey::isUsable($key)) {
            return CheckResult::fail(__('HEALTH_CHECK_KEY is too short'), 'php artisan skv:doctor --fix');
        }

        return CheckResult::pass(__('HEALTH_CHECK_KEY is set'));
    }

    /**
     * Writes a key when .env has none or a short one. A usable key is never replaced, because a monitor may use it;
     * a short one cannot be in use, since the endpoint refuses it (CD-4).
     */
    public function fix(DoctorContext $context): CheckResult
    {
        if (! HealthCheckKey::isUsable($context->env()->get('HEALTH_CHECK_KEY'))) {
            $context->env()->set(['HEALTH_CHECK_KEY' => Str::random(EnvironmentStep::GENERATED['HEALTH_CHECK_KEY'])]);
        }

        $context->forgetSync();

        return $this->run($context);
    }
}
