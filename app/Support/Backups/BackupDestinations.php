<?php

declare(strict_types=1);

namespace App\Support\Backups;

use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

/** The backups module's destinations as config/backup.php names them (§4.9, CD-6). */
final readonly class BackupDestinations
{
    /** @return list<BackupDestination> one per configured disk; BackupDestination::create() never throws */
    public static function all(): array
    {
        $name = (string) config('backup.backup.name');
        $disks = config('backup.backup.destination.disks', []);

        return array_values(array_map(
            fn (mixed $disk): BackupDestination => BackupDestination::create((string) $disk, $name),
            is_array($disks) ? $disks : [],
        ));
    }

    /** The age after which the newest backup is too old, from the first monitored backup (default one day). */
    public static function maximumAgeInDays(): int
    {
        $checks = config('backup.monitor_backups.0.health_checks', []);
        $days = is_array($checks) ? ($checks[MaximumAgeInDays::class] ?? 1) : 1;

        return max(1, is_numeric($days) ? (int) $days : 1);
    }
}
