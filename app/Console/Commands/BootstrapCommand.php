<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Setup\Support\Bootstrap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The Composer and installer hook (§5.2, RL-1, RL-13). It returns early while the installer defers Composer's
 * hooks, because the installer runs its own post-create-project hook afterwards.
 */
#[Description('Prepare a fresh copy of the kit: .env, key, Herd URL, SQLite (§5.2)')]
#[Signature('skv:bootstrap')]
final class BootstrapCommand extends Command
{
    public function handle(Bootstrap $bootstrap): int
    {
        if (getenv('LARAVEL_INSTALLER_DEFER_HOOKS') !== false && getenv('LARAVEL_INSTALLER_DEFER_HOOKS') !== '') {
            return self::SUCCESS;
        }

        foreach ($bootstrap->run() as $action) {
            $this->components->twoColumnDetail($action, __('done'));
        }

        $this->components->info(__('Open :url to finish setting up in the browser, or run php artisan skv:install.', ['url' => $bootstrap->appUrl()]));

        return self::SUCCESS;
    }
}
