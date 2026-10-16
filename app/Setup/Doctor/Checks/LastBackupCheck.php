<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Support\Backups\BackupDestinations;
use App\Support\Backups\BackupEncryption;
use App\Support\Skv;
use Illuminate\Support\Facades\Date;

/**
 * §4.9, CD-4: every backup destination is reachable and holds a backup newer than the monitor's maximum age, plus
 * config('skv.health_check.backup_grace_minutes'): an archive is named after the time its job starts, so while tonight's
 * backup runs, last night's is just over a day old.
 * Skipped while the backups module is off (§6.8, never a 503). A health check, not a doctor check: a fresh install
 * has no backup until the first night. Fails while the backups would be written unencrypted (CD-10): the package
 * silently skips encryption for a blank password, encryption 'none' or a zip without AES-256 (BackupEncryption).
 */
final readonly class LastBackupCheck implements Check
{
    public function key(): string
    {
        return 'last-backup';
    }

    public function label(): string
    {
        return __('Last backup');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! Skv::moduleEnabled('backups')) {
            return CheckResult::skipped(__('The backups module is off'));
        }

        if (! BackupEncryption::available()) {
            return CheckResult::fail(__('Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty or this PHP zip has no AES-256'), 'php artisan skv:doctor --fix');
        }

        $destinations = BackupDestinations::all();

        if ($destinations === []) {
            return CheckResult::fail(__('No backup disk is configured'), 'config/backup.php');
        }

        $oldestAllowed = Date::now()
            ->subDays(BackupDestinations::maximumAgeInDays())
            ->subMinutes(max(0, (int) config('skv.health_check.backup_grace_minutes', 120)));
        $newestDate = null;

        foreach ($destinations as $destination) {
            if (! $destination->isReachable()) {
                return CheckResult::fail(__('The backup disk :disk cannot be reached', ['disk' => $destination->diskName()]), 'config/filesystems.php');
            }

            $newest = $destination->newestBackup();

            if ($newest === null) {
                return CheckResult::fail(__('There is no backup on :disk yet', ['disk' => $destination->diskName()]), 'php artisan backup:run --only-db');
            }

            if ($newest->date()->lt($oldestAllowed)) {
                return CheckResult::fail(
                    __('The last backup on :disk is from :time', ['disk' => $destination->diskName(), 'time' => $newest->date()->diffForHumans()]),
                    'php artisan backup:run --only-db',
                );
            }

            $newestDate = $newestDate === null || $newest->date()->gt($newestDate) ? $newest->date() : $newestDate;
        }

        // The list is non-empty and every iteration returns or sets $newestDate, so PHPStan knows it is a date here.
        return CheckResult::pass(__('The last backup is from :time', ['time' => $newestDate->diffForHumans()]));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
