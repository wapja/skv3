<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Backups\RefuseUnencryptedBackups;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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
    ]);
});

it('refuses to start backup:run while the archive would not be encrypted (CD-10)', function (array $config): void {
    config($config);

    expect(fn (): int => Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]))
        ->toThrow(RuntimeException::class, 'php artisan skv:doctor --fix');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'no password' => [['backup.backup.password' => null]],
    'encryption off' => [['backup.backup.encryption' => 'none']],
]);

it('exits 1 for php artisan backup:run without a password (CD-10)', function (): void {
    config(['backup.backup.password' => '']);
    $output = new BufferedOutput;

    $exit = app(Kernel::class)->handle(new ArrayInput(['command' => 'backup:run', '--only-db' => true, '--disable-notifications' => true]), $output);

    expect($exit)->toBe(1)
        ->and($output->fetch())->toContain('config:cache');
    expect(Storage::disk('backups')->allFiles())->toBe([]);
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
