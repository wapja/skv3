<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\EnvironmentStep;
use App\Support\Skv;
use Illuminate\Support\Str;

/**
 * §4.9, CD-10: while the backups module is on, `.env` has a BACKUP_ARCHIVE_PASSWORD and the running configuration
 * has it too. The package writes unencrypted archives for a blank one without a warning, so this fails closed; the
 * fix writes a random one. Never shown. backup:run reads the configuration, not .env: a config cache taken before the
 * password existed, or a blank environment variable that overrides .env, leaves it without one (and
 * RefuseUnencryptedBackups refuses to start it).
 */
final readonly class BackupPasswordCheck implements Check
{
    public function key(): string
    {
        return 'backup-password';
    }

    public function label(): string
    {
        return __('Backup archive password');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! Skv::moduleEnabled('backups')) {
            return CheckResult::skipped(__('The backups module is off'));
        }

        if (($context->env()->get('BACKUP_ARCHIVE_PASSWORD') ?? '') === '') {
            return CheckResult::fail(__('BACKUP_ARCHIVE_PASSWORD is empty: backups are not encrypted'), 'php artisan skv:doctor --fix');
        }

        $configured = config('backup.backup.password');

        if (! is_string($configured) || $configured === '') {
            return CheckResult::fail(
                __('BACKUP_ARCHIVE_PASSWORD is in .env, but the running configuration has none: a stale config cache or a blank environment variable'),
                'php artisan config:cache',
            );
        }

        return CheckResult::pass(__('BACKUP_ARCHIVE_PASSWORD is set'));
    }

    /**
     * Writes a password only when there is none: an existing one is what opens the archives already made. Without a
     * config cache the next process reads it from .env, so the re-run sees it as that process will; with one, the
     * re-run keeps failing until php artisan config:cache.
     */
    public function fix(DoctorContext $context): CheckResult
    {
        if (($context->env()->get('BACKUP_ARCHIVE_PASSWORD') ?? '') === '') {
            $password = Str::random(EnvironmentStep::GENERATED['BACKUP_ARCHIVE_PASSWORD']);
            $context->env()->set(['BACKUP_ARCHIVE_PASSWORD' => $password]);

            if (! app()->configurationIsCached()) {
                config(['backup.backup.password' => $password]);
            }
        }

        $context->forgetSync();

        return $this->run($context);
    }
}
