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
});

$starting = fn (string $command): CommandStarting => new CommandStarting($command, new ArrayInput([]), new NullOutput);

it('points the dumper at the pg_dump the doctor finds before backup:run (RL-4)', function () use ($starting): void {
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => $this->bin]);

    new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath)($starting('backup:run'));

    expect(config('database.connections.pgsql.dump.dump_binary_path'))->toBe($this->bin);
});

it('finds Herd\'s newest PostgreSQL service when nothing else is configured (RL-4)', function () use ($starting): void {
    $services = $this->sandbox->path('herd-services');
    File::ensureDirectoryExists($services.'/18/bin');
    File::copy($this->bin.'/pg_dump', $services.'/18/bin/pg_dump');
    chmod($services.'/18/bin/pg_dump', 0755);

    new ConfigurePgDump($services, $this->sandbox->basePath)($starting('backup:run'));

    $configured = (string) config('database.connections.pgsql.dump.dump_binary_path');

    expect($configured)->not->toBe('')
        ->and(is_executable($configured.'/pg_dump'))->toBeTrue();
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
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => $this->bin]);
    app()->instance(ConfigurePgDump::class, new ConfigurePgDump($this->sandbox->path('no-herd-services'), $this->sandbox->basePath));

    event($starting('backup:run'));

    expect(config('database.connections.pgsql.dump.dump_binary_path'))->toBe($this->bin);
});
