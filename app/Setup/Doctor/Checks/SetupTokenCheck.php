<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Facades\File;

/** §5.4, §5.3.4: once setup has finished, the one-time setup token is gone; the fix deletes it. */
final readonly class SetupTokenCheck implements Check
{
    private const string TOKEN = 'storage/app/private/skv-setup-token';

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
        if ($context->marker === null) {
            return CheckResult::skipped(__('Setup has not finished yet'));
        }

        if (! file_exists($context->path(self::TOKEN))) {
            return CheckResult::pass(__('The setup token is gone'));
        }

        return CheckResult::warn(__('The setup token is still there'), 'php artisan skv:doctor --fix');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        if ($context->marker === null) {
            return null;
        }

        File::delete($context->path(self::TOKEN));
        $context->forgetSync();

        return $this->run($context);
    }
}
