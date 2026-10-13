<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Str;

/** §5.4: TENANCY_APEX_DOMAIN is empty (it then follows APP_URL) or equals the APP_URL host. */
final readonly class ApexCheck implements Check
{
    public function key(): string
    {
        return 'apex';
    }

    public function label(): string
    {
        return __('Apex domain');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $apex = Str::lower($context->env()->get('TENANCY_APEX_DOMAIN') ?? '');
        $host = parse_url($context->env()->get('APP_URL') ?? '', PHP_URL_HOST);
        $host = is_string($host) ? Str::lower($host) : '';

        if ($apex === '' || $apex === $host) {
            return CheckResult::pass(__('The apex domain matches APP_URL'));
        }

        return CheckResult::fail(
            __('TENANCY_APEX_DOMAIN (:apex) differs from the APP_URL host (:host)', ['apex' => $apex, 'host' => $host]),
            __('Set TENANCY_APEX_DOMAIN to the APP_URL host'),
        );
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
