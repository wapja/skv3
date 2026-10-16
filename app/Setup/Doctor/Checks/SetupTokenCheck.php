<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Marker;
use App\Setup\SetupToken;
use Illuminate\Support\Facades\File;

/**
 * §5.4, §5.3.4: once setup has finished, the one-time setup token is gone; the fix deletes it. "Finished" is the
 * marker file, as for SetupMode, not the parsed marker: an unreadable marker must not hide a leftover token (L-13).
 */
final readonly class SetupTokenCheck implements Check
{
    public function key(): string
    {
        return 'setup-token';
    }

    public function label(): string
    {
        return __('Setup token');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! is_file($context->path(Marker::FILE))) {
            return CheckResult::skipped(__('Setup has not finished yet'));
        }

        if (! file_exists($context->path(SetupToken::PATH))) {
            return CheckResult::pass(__('The setup token is gone'));
        }

        return CheckResult::warn(__('The setup token is still there'), 'php artisan skv:doctor --fix');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        if (! is_file($context->path(Marker::FILE))) {
            return null;
        }

        File::delete($context->path(SetupToken::PATH));
        $context->forgetSync();

        return $this->run($context);
    }
}
