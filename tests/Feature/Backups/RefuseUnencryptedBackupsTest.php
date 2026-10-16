<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Backups\RefuseUnencryptedBackups;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Config\Config;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

// The console kernel dispatches CommandStarting in tests only with this trait.
uses(WithConsoleEvents::class);

beforeEach(function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    // Should a run get through, it works in a sandbox and never looks for pg_dump in the repository's .env.
    $this->sandbox = setupSandbox();
    config([
        'backup.backup.temporary_directory' => $this->sandbox->path('backup-temp'),
        'database.connections.pgsql.dump.dump_binary_path' => $this->sandbox->path('no-pg-dump'),
        // No source connection: ConfigurePgDump leaves PGSSLMODE alone (L-05), and a run that got through would stop
        // at "no files to back up" instead of dumping.
        'backup.backup.source.databases' => [],
    ]);
    // The package's command takes its Config at construction, from an instance RefreshDatabase's migrate run already
    // built: rebuild it from the configuration above, as BackupRunTest does.
    Config::rebind();
});

/** O1: the refusal is the one message, with no exception class and no stack frame. */
$assertCleanRefusal = function (string $output): void {
    expect((string) preg_replace('/\s+/', ' ', $output))->toContain(RefuseUnencryptedBackups::MESSAGE)
        ->and($output)->not->toContain('Exception')
        ->and($output)->not->toMatch('/#\d+ /')
        ->and($output)->not->toContain('vendor/');
};

it('refuses to start backup:run with one error line while the archive would not be encrypted (CD-10, O1)', function (array $config) use ($assertCleanRefusal): void {
    config($config);

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);

    expect($exit)->toBe(1);
    $assertCleanRefusal(Artisan::output());
    expect(Storage::disk('backups')->allFiles())->toBe([]);
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'no password' => [['backup.backup.password' => null]],
    'encryption off' => [['backup.backup.encryption' => 'none']],
]);

it('exits 1 for php artisan backup:run without a password (CD-10, O1)', function () use ($assertCleanRefusal): void {
    config(['backup.backup.password' => '']);
    $output = new BufferedOutput;

    $exit = app(Kernel::class)->handle(new ArrayInput(['command' => 'backup:run', '--only-db' => true, '--disable-notifications' => true]), $output);

    expect($exit)->toBe(1);
    $assertCleanRefusal($output->fetch());
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('still stops a backup:run that is not the app\'s command (CD-7)', function (): void {
    config(['backup.backup.password' => '']);
    // Should the package's command hold the name again, the listener stays the backstop.
    Artisan::registerCommand(app(BackupCommand::class));
    $listener = new RefuseUnencryptedBackups;

    expect(function () use ($listener): void {
        $listener(new CommandStarting('backup:run', new ArrayInput([]), new NullOutput));
    })->toThrow(RuntimeException::class, 'php artisan skv:doctor --fix');
});

it('lets encrypted backups and every other command start (CD-10)', function (string $command, ?string $password): void {
    config(['backup.backup.password' => $password]);
    $listener = new RefuseUnencryptedBackups;

    $listener(new CommandStarting($command, new ArrayInput([]), new NullOutput));
})->throwsNoExceptions()->with([
    'an encrypted backup' => ['backup:run', 'correct-horse-battery'],
    'the list without a password' => ['backup:list', null],
    'the monitor without a password' => ['backup:monitor', null],
    'another command' => ['migrate', null],
]);
