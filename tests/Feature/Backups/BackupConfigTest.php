<?php

declare(strict_types=1);

/** covers §4.9, §6.8 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Exceptions\InvalidConfig;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

/**
 * Runs $callback with each variable set (a string) or removed (null) in getenv(), $_SERVER and $_ENV, the three places
 * env() reads ($_SERVER and $_ENV first), and restores all three afterwards (F28, L-03).
 *
 * @param  array<string, string|null>  $variables
 */
$withEnvironment = function (array $variables, Closure $callback): mixed {
    $saved = [];

    foreach ($variables as $key => $value) {
        $saved[$key] = [
            'getenv' => getenv($key),
            'server' => array_key_exists($key, $_SERVER) ? [$_SERVER[$key]] : [],
            'env' => array_key_exists($key, $_ENV) ? [$_ENV[$key]] : [],
        ];

        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv($key.'='.$value);
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($saved as $key => $original) {
            putenv($original['getenv'] === false ? $key : $key.'='.$original['getenv']);
            unset($_SERVER[$key], $_ENV[$key]);

            if ($original['server'] !== []) {
                $_SERVER[$key] = $original['server'][0];
            }

            if ($original['env'] !== []) {
                $_ENV[$key] = $original['env'][0];
            }
        }
    }
};

it('backs up the database only, to the dedicated backups disk (§4.9, CD-6)', function (): void {
    expect(config('backup.backup.source.files.include'))->toBe([])
        ->and(config('backup.backup.source.databases'))->toBe([config('database.default')])
        ->and(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('filesystems.disks.backups.driver'))->toBe('local')
        ->and(config('filesystems.disks.backups.root'))->toBe(storage_path('app/backups'));
});

it('backs up the app\'s default connection, also while DB_CONNECTION is unset (L-01)', function () use ($withEnvironment): void {
    /** @var array{0: array<string, mixed>, 1: array<string, mixed>} $configs */
    $configs = $withEnvironment(
        ['DB_CONNECTION' => null],
        fn (): array => [require config_path('backup.php'), require config_path('database.php')],
    );

    expect(data_get($configs[1], 'default'))->toBe('sqlite')
        ->and(data_get($configs[0], 'backup.source.databases'))->toBe([data_get($configs[1], 'default')]);
});

it('names the backup after the app, never after the kit (CD-6)', function (): void {
    $name = config('backup.backup.name');

    expect($name)->toBeString()->toMatch('/\A[a-z0-9-]+\z/')
        ->and(config('backup.monitor_backups.0.name'))->toBe($name)
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['backups'])
        ->and(config('backup.monitor_backups.0.health_checks.'.MaximumAgeInDays::class))->toBe(1);
});

it('encrypts the archive with BACKUP_ARCHIVE_PASSWORD (§4.9, L-03)', function () use ($withEnvironment): void {
    $backup = $withEnvironment(['BACKUP_ARCHIVE_PASSWORD' => 'from-the-environment'], fn (): mixed => require config_path('backup.php'));

    expect(data_get($backup, 'backup.password'))->toBe('from-the-environment')
        ->and(data_get($backup, 'backup.encryption'))->toBe('default')
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

it('lets only a valid notify and sender address through, so a typo never breaks every artisan command (§4.9, F28)', function () use ($withEnvironment): void {
    // The package's commands are built whenever Artisan boots, and their Config throws on an invalid address. Both
    // variables are set in $_SERVER and $_ENV too, which env() reads before putenv(), so no original value shadows them.
    /** @var array<string, mixed> $array */
    $array = $withEnvironment(
        ['BACKUP_NOTIFY_EMAIL' => 'not-an-address', 'MAIL_FROM_ADDRESS' => 'not-an-address'],
        fn (): mixed => require config_path('backup.php'),
    );

    expect(fn (): Config => Config::fromArray($array))->not->toThrow(InvalidConfig::class)
        ->and(data_get($array, 'notifications.mail.to'))->toBe('hello@example.com')
        ->and(data_get($array, 'notifications.mail.from.address'))->toBe('hello@example.com');
});

it('lists the backup keys in .env.example (§4.9, A6)', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^BACKUP_ARCHIVE_PASSWORD=$/m')
        ->toMatch('/^BACKUP_NOTIFY_EMAIL=$/m')
        ->toMatch('/^PG_DUMP_BINARY_PATH=$/m');
});

it('passes PG_DUMP_BINARY_PATH to the dumper as its binary directory, blank when unset (RL-4, L-03)', function (?string $value, string $expected) use ($withEnvironment): void {
    $database = $withEnvironment(['PG_DUMP_BINARY_PATH' => $value], fn (): mixed => require config_path('database.php'));

    expect(data_get($database, 'connections.pgsql.dump.dump_binary_path'))->toBe($expected)
        ->and(config('database.connections.pgsql.dump'))->toHaveKey('dump_binary_path');
})->with([
    'set' => ['/opt/postgresql/18/bin', '/opt/postgresql/18/bin'],
    'unset' => [null, ''],
]);

it('writes archives on the backups disk readable by this account only (§4.9, G6-10)', function (): void {
    // As the package writes them: through the Flysystem driver's writeStream(), under a world-readable umask.
    $root = storage_path('framework/testing/backups-'.Str::uuid());
    $umask = umask(0022);

    try {
        $disk = Storage::build([...config('filesystems.disks.backups'), 'root' => $root]);
        $stream = fopen('php://memory', 'r+') ?: throw new LogicException('Could not open a memory stream.');
        fwrite($stream, 'archive');
        rewind($stream);
        $disk->getDriver()->writeStream('app/2026-10-16-02-00-00.zip', $stream);
        clearstatcache();

        expect(fileperms($root.'/app/2026-10-16-02-00-00.zip') & 0777)->toBe(0600)
            ->and(fileperms($root.'/app') & 0777)->toBe(0700);
    } finally {
        umask($umask);
        File::deleteDirectory($root);
    }
});
