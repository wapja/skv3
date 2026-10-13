<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/**
 * §5.4: public/storage exists. The fix runs `php artisan storage:link` as a child process in the base path, so it
 * links the examined project (and honours its filesystems.links), never the running application.
 */
final readonly class StorageLinkCheck implements Check
{
    public function key(): string
    {
        return 'storage-link';
    }

    public function label(): string
    {
        return __('Storage link');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $link = $context->path('public/storage');

        if (is_link($link) || file_exists($link)) {
            return CheckResult::pass(__('public/storage is linked'));
        }

        return CheckResult::warn(__('public/storage is missing'), 'php artisan storage:link');
    }

    public function fix(DoctorContext $context): CheckResult
    {
        $context->process(60)->run([PHP_BINARY, 'artisan', 'storage:link', '--no-interaction']);
        $context->forgetSync();

        return $this->run($context);
    }
}
