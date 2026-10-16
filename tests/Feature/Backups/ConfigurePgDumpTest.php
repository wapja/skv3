<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Support\Backups\ConfigurePgDump;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    $this->bin = $this->sandbox->path('pg/bin');
    File::ensureDirectoryExists($this->bin);
    File::put($this->bin.'/pg_dump', "#!/bin/sh\nexit 0\n");
    chmod($this->bin.'/pg_dump', 0755);
    config(['database.connections.pgsql.dump.dump_binary_path' => '']);
    // Every backup:run listener may run (the last test fires the event): never the repository's storage/app/backup-temp.
    config(['backup.backup.temporary_directory' => $this->sandbox->path('backup-temp')]);
    // L-05: ConfigurePgDump exports PGSSLMODE; start without one and put the original back afterwards.
    $this->pgSslMode = ['getenv' => getenv('PGSSLMODE'), 'server' => $_SERVER['PGSSLMODE'] ?? null, 'env' => $_ENV['PGSSLMODE'] ?? null];
    putenv('PGSSLMODE');
    unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);
});

afterEach(function (): void {
    /** @var array{getenv: string|false, server: mixed, env: mixed} $original */
    $original = $this->pgSslMode;
    putenv($original['getenv'] === false ? 'PGSSLMODE' : 'PGSSLMODE='.$original['getenv']);
    unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);

    if ($original['server'] !== null) {
        $_SERVER['PGSSLMODE'] = $original['server'];
    }

    if ($original['env'] !== null) {
        $_ENV['PGSSLMODE'] = $original['env'];
    }
});

$starting = fn (string $command): CommandStarting => new CommandStarting($command, new ArrayInput([]), new NullOutput);

it('points the dumper at the pg_dump the doctor finds before backup:run (RL-4)', function () use ($starting): void {
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => $this->bin]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting('backup:run'));

    expect(config('database.connections.pgsql.dump.dump_binary_path'))->toBe($this->bin);
});

it('finds Herd\'s newest PostgreSQL service when nothing else is configured (RL-4, CD-6, L-02)', function () use ($starting): void {
    // CD-6: for a server on this machine Herd's services come before the PATH, so no PATH pg_dump hides the order.
    config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => null]);
    $services = $this->sandbox->path('herd/services/postgresql');

    foreach (['9.6', '10', '18'] as $version) {
        File::ensureDirectoryExists($services.'/'.$version.'/bin');
        File::copy($this->bin.'/pg_dump', $services.'/'.$version.'/bin/pg_dump');
        chmod($services.'/'.$version.'/bin/pg_dump', 0755);
    }

    new ConfigurePgDump($services, $this->sandbox->basePath)($starting('backup:run'));

    expect(config('database.connections.pgsql.dump.dump_binary_path'))->toBe($services.'/18/bin');
});

it('keeps a configured path and leaves other commands alone (RL-4)', function (string $command, string $configured) use ($starting): void {
    config(['database.connections.pgsql.dump.dump_binary_path' => $configured]);
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => $this->bin]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting($command));

    expect(config('database.connections.pgsql.dump.dump_binary_path'))->toBe($configured);
})->with([
    'configured already' => ['backup:run', '/opt/postgresql/bin'],
    'another command' => ['migrate', ''],
]);

it('runs as a CommandStarting listener (RL-4)', function () use ($starting): void {
    // RefuseUnencryptedBackups listens first and stops a backup:run without a password (CD-10).
    config(['backup.backup.password' => 'correct-horse-battery']);
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => $this->bin]);
    app()->instance(ConfigurePgDump::class, new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath));

    event($starting('backup:run'));

    expect(config('database.connections.pgsql.dump.dump_binary_path'))->toBe($this->bin);
});

it('hands the backup source\'s sslmode to pg_dump as PGSSLMODE (L-05)', function () use ($starting): void {
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting('backup:run'));

    // Symfony's Process passes on the getenv() keys that $_SERVER also holds, plus every $_ENV key.
    expect(getenv('PGSSLMODE'))->toBe('verify-full')
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe('verify-full')
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe('verify-full');
});

it('leaves PGSSLMODE alone for other commands and other databases (L-05)', function (string $command, string $source) use ($starting): void {
    config([
        'backup.backup.source.databases' => [$source],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting($command));

    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE');
})->with([
    'another command' => ['migrate', 'pgsql'],
    'a SQLite source' => ['backup:run', 'sqlite'],
]);
