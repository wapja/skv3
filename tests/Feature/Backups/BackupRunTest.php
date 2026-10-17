<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Setup\Detection;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config;
use Tests\Fixtures\PgSslModeSnapshot;

/** The pg_dump directory when it can dump the test server, else null (an older client refuses a newer server). */
$usablePgDump = function (): ?string {
    if (config('database.default') !== 'pgsql') {
        return null;
    }

    $toolchain = new Toolchain(storage_path('framework/testing/home'));
    $path = new PostgresBinaries()->find(new DoctorContext(storage_path('framework/testing'), $toolchain, Detection::none(), null), 'pg_dump');

    if ($path === null) {
        return null;
    }

    // The one test that runs a real pg_dump (AGENTS.md › Ops): its version probe runs for real, not faked (RL-12).
    Process::preventStrayProcesses(false);
    // F19: through Process with the toolchain's environment, never exec(). The package's pg_dump runs through Symfony's
    // Process directly, so the facade's fake never touches it.
    preg_match('/(\d+)(?:\.\d+)?/', Process::env($toolchain->environment())->run([$path, '--version'])->output(), $client);
    preg_match('/^(\d+)/', DB::connection()->getServerVersion(), $server);

    return isset($client[1], $server[1]) && (int) $client[1] >= (int) $server[1] ? dirname($path) : null;
};

beforeEach(function (): void {
    // L7b-68: backup:run exports the source's sslmode as PGSSLMODE; put the original back afterwards.
    $this->pgSslMode = PgSslModeSnapshot::takeAndClear();
});

afterEach(function (): void {
    $this->pgSslMode->restore();
});

it('backs up the database into an encrypted archive on the backups disk (§4.9)', function () use ($usablePgDump): void {
    Storage::fake('backups');
    // The package creates, empties and deletes its working directory: a sandbox, never the repository's storage/app/backup-temp.
    $sandbox = setupSandbox();
    config([
        'database.connections.pgsql.dump.dump_binary_path' => $usablePgDump(),
        'backup.backup.password' => 'correct-horse-battery',
        'backup.backup.temporary_directory' => $sandbox->path('backup-temp'),
    ]);
    Config::rebind();

    $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    // F23: Artisan::output() empties its buffer, so it is read once and checked twice.
    $output = Artisan::output();
    $name = (string) config('backup.backup.name');
    $newest = BackupDestination::create('backups', $name)->newestBackup();

    expect($exit)->toBe(0, $output)
        ->and($newest)->not->toBeNull();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path((string) $newest?->path()));
    $entry = $zip->statIndex(0);
    $zip->close();

    expect($entry)->toBeArray()
        ->and($entry['encryption_method'] ?? 0)->not->toBe(0)
        ->and($output)->not->toContain('correct-horse-battery');
})->skip(fn (): bool => $usablePgDump() === null, 'needs PostgreSQL and a pg_dump at least as new as the server');
