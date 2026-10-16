<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Console\Commands\BackupRunCommand;
use App\Support\Backups\PrivateBackupTemp;
use App\Support\Backups\RefuseUnencryptedBackups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Exceptions\BackupFailed;

beforeEach(function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    $this->sandbox = setupSandbox();
    config([
        'backup.backup.temporary_directory' => $this->sandbox->path('backup-temp'),
        'database.connections.pgsql.dump.dump_binary_path' => $this->sandbox->path('no-pg-dump'),
        // No source connection: a run that starts stops at "no files to back up" and dumps nothing.
        'backup.backup.source.databases' => [],
    ]);
    // The package's command takes its Config at construction, from an instance RefreshDatabase's migrate run already
    // built: rebuild it from the configuration above, as BackupRunTest does. Without this, a run backs up :memory:.
    Config::rebind();
});

it('owns the backup:run name over the package\'s command (CD-7, O1)', function (): void {
    expect(Artisan::all()['backup:run'] ?? null)
        ->toBeInstanceOf(BackupRunCommand::class)
        ->toBeInstanceOf(BackupCommand::class);
});

it('refuses by itself, without the CommandStarting backstop (CD-7, O1)', function (array $config): void {
    // Without WithConsoleEvents no listener runs: the refusal is the command's own.
    config($config);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and((string) preg_replace('/\s+/', ' ', $output))->toContain(RefuseUnencryptedBackups::MESSAGE)
        ->and($output)->not->toContain('Starting backup');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'encryption off' => [['backup.backup.encryption' => 'none']],
]);

it('judges the configuration that --config names, not the default one (CD-7)', function (): void {
    // The default configuration encrypts (useBackupsModule() sets a password); the one --config names would not.
    /** @var array<string, mixed> $backup */
    $backup = config('backup');
    config(['backup_plain' => array_replace_recursive($backup, ['backup' => ['password' => '']])]);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--config' => 'backup_plain', '--disable-notifications' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and((string) preg_replace('/\s+/', ' ', $output))->toContain(RefuseUnencryptedBackups::MESSAGE)
        ->and($output)->not->toContain('Starting backup');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('hands an encrypted backup to the package (CD-7)', function (): void {
    Exceptions::fake();

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    $output = Artisan::output();

    // The package's job starts, then stops at "no files to back up" (no source connection).
    expect($exit)->toBe(1)
        ->and($output)->toContain('Starting backup')
        ->and((string) preg_replace('/\s+/', ' ', $output))->not->toContain(RefuseUnencryptedBackups::MESSAGE);
    Exceptions::assertReported(BackupFailed::class);
});

it('prints its encryption refusal in the app\'s language (CD-7, M27)', function (): void {
    app()->setLocale('nl');
    config(['backup.backup.password' => '']);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    expect($exit)->toBe(1)
        ->and($output)->toContain(__(RefuseUnencryptedBackups::MESSAGE))
        ->not->toContain(RefuseUnencryptedBackups::MESSAGE);
});

it('refuses with one translated line while its temporary directory is not private to this account (CD-10, M22)', function (Closure $share): void {
    // Arrange: the directory the plaintext dump would go to is one the listener may not narrow to 0700.
    app()->setLocale('nl');
    $share($this->sandbox->path('backup-temp'));

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and((string) preg_replace('/\s+/', ' ', $output))->toContain(__(PrivateBackupTemp::MESSAGE))
        ->not->toContain(PrivateBackupTemp::MESSAGE)
        ->and($output)->not->toContain('Starting backup');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
})->with([
    'a symbolic link' => [function (string $temp): void {
        File::ensureDirectoryExists($temp.'-target');
        symlink($temp.'-target', $temp);
    }],
    'a shared sticky directory' => [function (string $temp): void {
        File::ensureDirectoryExists($temp);
        chmod($temp, 01777);
    }],
    'the system temporary directory' => [function (string $temp): void {
        File::ensureDirectoryExists($temp);
        app()->instance(PrivateBackupTemp::class, new PrivateBackupTemp(systemTemp: $temp));
    }],
]);
