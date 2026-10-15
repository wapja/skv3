<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Marker;
use JsonException;

/**
 * §5.4, A8: the install marker reads back. A marker that is not a JSON object would hide the installed answers from
 * every re-run and from the doctor's marker-gated rows, so it fails instead of reading as "not installed".
 */
final readonly class MarkerCheck implements Check
{
    public function key(): string
    {
        return 'marker';
    }

    public function label(): string
    {
        return __('Install marker');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! is_file($context->path(Marker::FILE))) {
            return CheckResult::skipped(__('The app has not been set up yet'));
        }

        try {
            Marker::read($context->setupContext());
        } catch (JsonException) {
            return CheckResult::fail(
                __('The install marker :file is not valid JSON', ['file' => Marker::FILE]),
                __('Restore it from a backup, or delete it and run php artisan skv:install'),
            );
        }

        return CheckResult::pass(__('The install marker reads back'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
