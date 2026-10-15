<?php

declare(strict_types=1);

/** covers §4.9, §6.8 */

use Spatie\Backup\Config\Config;
use Spatie\Backup\Exceptions\InvalidConfig;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

it('backs up the database only, to the dedicated backups disk (§4.9, CD-6)', function (): void {
    expect(config('backup.backup.source.files.include'))->toBe([])
        ->and(config('backup.backup.source.databases'))->toBe([config('database.default')])
        ->and(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('filesystems.disks.backups.driver'))->toBe('local')
        ->and(config('filesystems.disks.backups.root'))->toBe(storage_path('app/backups'));
});

it('names the backup after the app, never after the kit (CD-6)', function (): void {
    $name = config('backup.backup.name');

    expect($name)->toBeString()->toMatch('/\A[a-z0-9-]+\z/')
        ->and(config('backup.monitor_backups.0.name'))->toBe($name)
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['backups'])
        ->and(config('backup.monitor_backups.0.health_checks.'.MaximumAgeInDays::class))->toBe(1);
});

it('encrypts the archive with BACKUP_ARCHIVE_PASSWORD (§4.9)', function (): void {
    $config = (string) file_get_contents(config_path('backup.php'));

    expect($config)->toContain("'password' => env('BACKUP_ARCHIVE_PASSWORD')")
        ->and(config('backup.backup.encryption'))->toBe('default');
});

it('mails on failure only (§4.9)', function (): void {
    /** @var array<class-string, list<string>> $channels */
    $channels = config('backup.notifications.notifications');

    expect($channels)->toBe([
        BackupHasFailedNotification::class => ['mail'],
        UnhealthyBackupWasFoundNotification::class => ['mail'],
        CleanupHasFailedNotification::class => ['mail'],
        BackupWasSuccessfulNotification::class => [],
        HealthyBackupWasFoundNotification::class => [],
        CleanupWasSuccessfulNotification::class => [],
    ]);
});

it('builds the package configuration, so a blank BACKUP_NOTIFY_EMAIL falls back to a valid address (§4.9)', function (): void {
    // The suite sets no BACKUP_NOTIFY_EMAIL: the fallback (MAIL_FROM_ADDRESS) must pass the package's email check.
    $config = Config::fromArray(config('backup'));

    expect($config->notifications->mail->to)->toBe(config('backup.notifications.mail.to'))
        ->and(filter_var(config('backup.notifications.mail.to'), FILTER_VALIDATE_EMAIL))->not->toBeFalse();
});

it('lets only a valid notify and sender address through, so a typo never breaks every artisan command (§4.9)', function (): void {
    // The package's commands are built whenever Artisan boots, and their Config throws on an invalid address.
    $original = [
        'getenv' => getenv('MAIL_FROM_ADDRESS'),
        'server' => $_SERVER['MAIL_FROM_ADDRESS'] ?? null,
        'env' => $_ENV['MAIL_FROM_ADDRESS'] ?? null,
    ];
    putenv('BACKUP_NOTIFY_EMAIL=not-an-address');
    putenv('MAIL_FROM_ADDRESS=not-an-address');
    $_SERVER['MAIL_FROM_ADDRESS'] = $_ENV['MAIL_FROM_ADDRESS'] = 'not-an-address';

    try {
        /** @var array<string, mixed> $array */
        $array = require config_path('backup.php');

        expect(fn (): Config => Config::fromArray($array))->not->toThrow(InvalidConfig::class)
            ->and(filter_var(data_get($array, 'notifications.mail.to'), FILTER_VALIDATE_EMAIL))->not->toBeFalse()
            ->and(filter_var(data_get($array, 'notifications.mail.from.address'), FILTER_VALIDATE_EMAIL))->not->toBeFalse();
    } finally {
        putenv('BACKUP_NOTIFY_EMAIL');
        putenv($original['getenv'] === false ? 'MAIL_FROM_ADDRESS' : 'MAIL_FROM_ADDRESS='.$original['getenv']);

        unset($_SERVER['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_ADDRESS']);

        if ($original['server'] !== null) {
            $_SERVER['MAIL_FROM_ADDRESS'] = $original['server'];
        }

        if ($original['env'] !== null) {
            $_ENV['MAIL_FROM_ADDRESS'] = $original['env'];
        }
    }
});

it('lists the backup keys in .env.example (§4.9, A6)', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^BACKUP_ARCHIVE_PASSWORD=$/m')
        ->toMatch('/^BACKUP_NOTIFY_EMAIL=$/m')
        ->toMatch('/^PG_DUMP_BINARY_PATH=$/m');
});

it('passes PG_DUMP_BINARY_PATH to the dumper as its binary directory (RL-4)', function (): void {
    expect(config('database.connections.pgsql.dump'))->toHaveKey('dump_binary_path')
        ->and((string) file_get_contents(config_path('database.php')))->toContain("'dump_binary_path' => env('PG_DUMP_BINARY_PATH', '')");
});
