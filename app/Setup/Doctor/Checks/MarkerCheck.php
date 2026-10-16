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
        $path = $context->path(Marker::FILE);

        if (! is_file($path)) {
            return CheckResult::skipped(__('The app has not been set up yet'));
        }

        // RL-2: a symlink is refused and an unreadable file is not "not valid JSON" (F3 (4), L-09).
        if (is_link($path) || ! is_readable($path)) {
            return CheckResult::fail(
                __('The install marker :file cannot be read', ['file' => Marker::FILE]),
                __('Restore it from a backup, or delete it and run php artisan skv:install'),
            );
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
