<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Setup\Detection;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config;

/** The pg_dump directory when it can dump the test server, else null (an older client refuses a newer server). */
$usablePgDump = function (): ?string {
    if (config('database.default') !== 'pgsql') {
        return null;
    }

    $path = new PostgresBinaries()->find(new DoctorContext(storage_path('framework/testing'), new Toolchain(storage_path('framework/testing/home')), Detection::none(), null), 'pg_dump');

    if ($path === null) {
        return null;
    }

    exec(escapeshellarg($path).' --version', $output);
    preg_match('/(\d+)(?:\.\d+)?/', implode(' ', $output), $client);
    $row = DB::selectOne('show server_version_num');
    $server = intdiv((int) ($row->server_version_num ?? 0), 10000);

    return isset($client[1]) && (int) $client[1] >= $server ? dirname($path) : null;
};

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
    $name = (string) config('backup.backup.name');
    $newest = BackupDestination::create('backups', $name)->newestBackup();

    expect($exit)->toBe(0, Artisan::output())
        ->and($newest)->not->toBeNull();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path((string) $newest?->path()));
    $entry = $zip->statIndex(0);
    $zip->close();

    expect($entry)->toBeArray()
        ->and($entry['encryption_method'] ?? 0)->not->toBe(0)
        ->and(Artisan::output())->not->toContain('correct-horse-battery');
})->skip(fn (): bool => $usablePgDump() === null, 'needs PostgreSQL and a pg_dump at least as new as the server');
