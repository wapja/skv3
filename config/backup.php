<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
 * The backups module (§4.9, §6.8, CD-6): the database only, encrypted with BACKUP_ARCHIVE_PASSWORD, on the dedicated
 * `backups` disk, mail on failure only, monitored for a backup at most a day old. Whether it runs is the module flag
 * (config('skv.modules.backups'), always off on SQLite); the schedule checks it (routes/console.php).
 */
$name = Str::slug((string) env('APP_NAME', 'app')) ?: 'app';

// The package throws on an invalid recipient or sender while Artisan boots (every command), so only valid ones get
// through: the sender is MAIL_FROM_ADDRESS, else a placeholder; the recipient BACKUP_NOTIFY_EMAIL, else that sender.
$sender = (string) env('MAIL_FROM_ADDRESS', 'hello@example.com');
$sender = filter_var($sender, FILTER_VALIDATE_EMAIL) !== false ? $sender : 'hello@example.com';
$notify = (string) env('BACKUP_NOTIFY_EMAIL', '');
$notifyTo = filter_var($notify, FILTER_VALIDATE_EMAIL) !== false ? $notify : $sender;

return [
    'backup' => [
        'name' => $name,
        'source' => [
            'files' => [
                'include' => [],
                'exclude' => [],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],
            'databases' => [env('DB_CONNECTION', 'pgsql')],
        ],
        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',
        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => ['backups'],
            'continue_on_failure' => false,
        ],
        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',
        'verify_backup' => false,
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            // A blank or invalid BACKUP_NOTIFY_EMAIL falls back to the sender (see $notifyTo above).
            'to' => $notifyTo,
            'from' => [
                'address' => $sender,
                'name' => env('MAIL_FROM_NAME', 'Laravel'),
            ],
        ],
    ],

    'monitor_backups' => [
        [
            'name' => $name,
            'disks' => ['backups'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 4,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],
];
