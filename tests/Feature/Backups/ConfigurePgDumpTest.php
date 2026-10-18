<?php

declare(strict_types=1);

/** covers §4.9.3 */

use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Support\Backups\ConfigurePgDump;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Backup\Commands\BackupCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Fixtures\PgSslModeSnapshot;

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
    $this->pgSslMode = PgSslModeSnapshot::takeAndClear();
});

afterEach(function (): void {
    $this->pgSslMode->restore();
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

it('hands the backup source\'s sslmode to pg_dump as PGSSLMODE (L-05)', function (): void {
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode();

    // Symfony's Process passes on the getenv() keys that $_SERVER also holds, plus every $_ENV key.
    expect(getenv('PGSSLMODE'))->toBe('verify-full')
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe('verify-full')
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe('verify-full');
});

it('reads the sslmode from the connection\'s url as the app and the dumper do (L-05)', function (): void {
    // ConfigurationUrlParser lets the url's query override the sslmode key (DATABASE_URL on managed PostgreSQL).
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.url' => 'pgsql://backup:secret@db.example.com:5432/app?sslmode=verify-full',
        'database.connections.pgsql.sslmode' => 'prefer',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode();

    expect(getenv('PGSSLMODE'))->toBe('verify-full')
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe('verify-full')
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe('verify-full');
});

it('leaves PGSSLMODE alone for a SQLite source and a connection --db-name leaves out (L-05, L7b-68)', function (array $sources, ?string $dbName): void {
    config([
        'backup.backup.source.databases' => $sources,
        'database.connections.pgsql.sslmode' => 'verify-full',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode($dbName === null ? [] : [$dbName]);

    // L7b-67: all three stores; $_SERVER is the one Symfony's Process hands pg_dump.
    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE')
        ->and($_SERVER)->not->toHaveKey('PGSSLMODE');
})->with([
    'a SQLite source' => [['sqlite'], null],
    'a PostgreSQL source --db-name leaves out' => [['pgsql', 'sqlite'], 'sqlite'],
]);

it('takes the sslmode of the connection --db-name names, not the other sources\' (L7b-68)', function (): void {
    // pgsql's stricter mode would win were the sources not narrowed to the one --db-name names.
    config([
        'backup.backup.source.databases' => ['pgsql', 'pgsql_reports'],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql_reports' => [...config('database.connections.pgsql'), 'sslmode' => 'require'],
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode(['pgsql_reports']);

    expect(getenv('PGSSLMODE'))->toBe('require');
});

it('exports the strictest sslmode of the PostgreSQL sources it dumps, in whatever order they are listed (L7b-68)', function (string $first, string $second, string $expected): void {
    // spatie's BackupJob runs every source's pg_dump in this one process, so they all inherit one PGSSLMODE.
    config([
        'backup.backup.source.databases' => ['pgsql', 'sqlite', 'pgsql_reports'],
        'database.connections.pgsql.sslmode' => $first,
        'database.connections.pgsql_reports' => [...config('database.connections.pgsql'), 'sslmode' => $second],
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)->passSslMode();

    expect(getenv('PGSSLMODE'))->toBe($expected)
        ->and($_SERVER['PGSSLMODE'] ?? null)->toBe($expected)
        ->and($_ENV['PGSSLMODE'] ?? null)->toBe($expected);
})->with([
    'require, then verify-full' => ['require', 'verify-full', 'verify-full'],
    'verify-full, then require' => ['verify-full', 'require', 'verify-full'],
    'disable, then verify-ca' => ['disable', 'verify-ca', 'verify-ca'],
    'verify-ca, then verify-full' => ['verify-ca', 'verify-full', 'verify-full'],
    'allow, then prefer' => ['allow', 'prefer', 'prefer'],
    'prefer, then disable' => ['prefer', 'disable', 'prefer'],
    // An unknown mode wins, so pg_dump refuses it instead of the run falling back to the weaker source's mode.
    'a misspelt mode, then require' => ['require', 'verify_full', 'verify_full'],
]);

it('leaves PGSSLMODE to backup:run, which knows its --config, for every command it listens to (L-05, L7b-68)', function (string $command) use ($starting): void {
    // Before handle() the default configuration's sources are all the listener could read.
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting($command));

    expect(getenv('PGSSLMODE'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('PGSSLMODE')
        ->and($_SERVER)->not->toHaveKey('PGSSLMODE');
})->with(['backup:run', 'migrate']);

it('still exports the default sources\' sslmode should the package\'s command hold backup:run again (L-05, L7b-68)', function () use ($starting): void {
    config([
        'backup.backup.source.databases' => ['pgsql'],
        'database.connections.pgsql.sslmode' => 'verify-full',
        'database.connections.pgsql.dump.dump_binary_path' => '/opt/postgresql/bin',
    ]);
    // The backstop path, bound as RefuseUnencryptedBackupsTest binds it: nothing else would export PGSSLMODE.
    Artisan::registerCommand(app(BackupCommand::class));

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting('backup:run'));

    expect(getenv('PGSSLMODE'))->toBe('verify-full');
});
