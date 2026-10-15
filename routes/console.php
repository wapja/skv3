<?php

declare(strict_types=1);

use App\Support\Backups\BackupEncryption;
use App\Support\Scheduler\Heartbeat;
use App\Support\Skv;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| §4.9: the ops schedule, each job on one server and never twice at once. The backup jobs run only while the backups
| module is on (§6.8; always off on SQLite), and backup:run only while it would encrypt the archive (CD-10): the
| package writes a plaintext dump for a blank password and still exits 0 (RefuseUnencryptedBackups also stops a
| backup:run started by hand). backup:monitor keeps running, so a missing backup is still reported, and last-backup
| fails on /health-check. activitylog:clean asks for confirmation in production, hence --force. model:prune removes
| expired handoff tokens (HandoffToken is the only prunable model).
*/
$backupsEnabled = fn (): bool => Skv::moduleEnabled('backups');
$encryptedBackupsEnabled = fn (): bool => Skv::moduleEnabled('backups') && BackupEncryption::available();

Schedule::command('activitylog:clean --force')->dailyAt('01:00')->onOneServer()->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('01:30')->when($backupsEnabled)->onOneServer()->withoutOverlapping();
Schedule::command('backup:run --only-db')->dailyAt('02:00')->when($encryptedBackupsEnabled)->onOneServer()->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('03:00')->when($backupsEnabled)->onOneServer()->withoutOverlapping();
Schedule::command('queue:prune-failed')->dailyAt('03:30')->onOneServer()->withoutOverlapping();
Schedule::command('model:prune')->dailyAt('04:00')->onOneServer()->withoutOverlapping();
Schedule::command('auth:clear-resets')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

// RL-8: the heartbeat the System page shows.
Schedule::call(fn () => app(Heartbeat::class)->beat())->everyMinute()->name('scheduler-heartbeat')->onOneServer();
