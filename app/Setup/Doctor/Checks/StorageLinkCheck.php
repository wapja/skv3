<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\EnvironmentStep;

/**
 * §5.4: public/storage is the storage link, resolving to storage/app/public (D63). Missing, it warns; anything else in
 * its place fails, because a link to the project root would serve .env under /storage and a dangling link or a
 * directory serves no upload. The fix runs `php artisan storage:link` as a child process in the base path, so it links
 * the examined project (and honours its filesystems.links), never the running application; with --force only while a
 * link is in the way, which storage:link replaces, while it never removes a directory or a file.
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

        if (EnvironmentStep::isStorageLink($link, $context->path('storage/app/public'))) {
            return CheckResult::pass(__('public/storage is linked'));
        }

        if (is_link($link)) {
            return CheckResult::fail(__('public/storage is not a link to storage/app/public'), 'php artisan storage:link --force');
        }

        if (file_exists($link)) {
            return CheckResult::fail(__('public/storage is not a link to storage/app/public'), __('Move public/storage aside, then run php artisan storage:link'));
        }

        return CheckResult::warn(__('public/storage is missing'), 'php artisan storage:link');
    }

    public function fix(DoctorContext $context): CheckResult
    {
        $force = is_link($context->path('public/storage')) ? ['--force'] : [];

        $context->process(60)->run([PHP_BINARY, 'artisan', 'storage:link', '--no-interaction', ...$force]);
        $context->forgetSync();

        return $this->run($context);
    }
}
