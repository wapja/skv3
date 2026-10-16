<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use App\Support\Backups\BackupEncryption;
use App\Support\Backups\PrivateBackupTemp;
use App\Support\Skv;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The ops schedule of §4.9 (CD-8). routes/console.php defines it on the scheduler's Schedule, and ScheduledTasks
 * defines it again on a fresh one for the System page, so a page render never boots the console kernel.
 *
 * The jobs run on one server and never twice at once, except the heartbeat (no overlap lock) and backup-temp-cleanup
 * (hourly on every server, because each has its own temporary directory, and also while the backups module is off,
 * so a dump left from before it was switched off still goes). The backup jobs' overlap lock expires after 12
 * hours, so a killed run never blocks the next night (F6). The backup jobs run only while the backups module is on
 * (§6.8; always off on SQLite), and backup:run only while it would encrypt the archive (DEC-32 CD-10): the package
 * writes a plaintext dump for a blank password and still exits 0. backup:monitor keeps running, so a missing backup
 * is still reported, and last-backup fails on /health-check. activitylog:clean asks for confirmation in production,
 * hence --force. model:prune removes expired handoff tokens (HandoffToken is the only prunable model).
 */
final readonly class OpsSchedule
{
    public static function define(Schedule $schedule): void
    {
        $backupsEnabled = fn (): bool => Skv::moduleEnabled('backups');
        $encryptedBackupsEnabled = fn (): bool => Skv::moduleEnabled('backups') && BackupEncryption::available();

        $schedule->command('activitylog:clean --force')->dailyAt('01:00')->onOneServer()->withoutOverlapping();
        // CD-10, F18: a killed backup:run leaves its plaintext dump behind; each server empties its own stale files
        // every hour. No module filter: removeStale() does nothing without the directory.
        $schedule->call(function (): void {
            app(PrivateBackupTemp::class)->removeStale();
        })->hourlyAt(15)->name('backup-temp-cleanup');
        $schedule->command('backup:clean')->dailyAt('01:30')->when($backupsEnabled)->onOneServer()->withoutOverlapping(720);
        $schedule->command('backup:run --only-db')->dailyAt('02:00')->when($encryptedBackupsEnabled)->onOneServer()->withoutOverlapping(720);
        $schedule->command('backup:monitor')->dailyAt('03:00')->when($backupsEnabled)->onOneServer()->withoutOverlapping(720);
        $schedule->command('queue:prune-failed')->dailyAt('03:30')->onOneServer()->withoutOverlapping();
        $schedule->command('model:prune')->dailyAt('04:00')->onOneServer()->withoutOverlapping();
        $schedule->command('auth:clear-resets')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

        // RL-8: the heartbeat the System page shows.
        $schedule->call(function (): void {
            app(Heartbeat::class)->beat();
        })->everyMinute()->name('scheduler-heartbeat')->onOneServer();
    }
}
