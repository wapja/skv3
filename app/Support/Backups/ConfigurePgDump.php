<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use Illuminate\Console\Events\CommandStarting;

/**
 * Before backup:run, fills an empty `database.connections.pgsql.dump.dump_binary_path` with the directory of the
 * pg_dump the doctor finds (RL-4, CD-6): PG_DUMP_BINARY_PATH, then for a server on this machine Herd's newest trusted
 * PostgreSQL service before the toolchain PATH, else the PATH first (PostgresBinaries). A cron job has no Herd PATH,
 * so without this the nightly backup would not find Herd's pg_dump.
 */
final readonly class ConfigurePgDump
{
    /** @param  string|null  $basePath  the project whose .env is read; tests pass a sandbox */
    public function __construct(
        private string $herdServices = PostgresBinaries::HERD_SERVICES,
        private ?string $basePath = null,
    ) {}

    public function __invoke(CommandStarting $event): void
    {
        if ($event->command !== 'backup:run' || (string) config('database.connections.pgsql.dump.dump_binary_path', '') !== '') {
            return;
        }

        $path = new PostgresBinaries($this->herdServices)->find(DoctorContext::withoutDetection($this->basePath), 'pg_dump');

        if ($path !== null) {
            config(['database.connections.pgsql.dump.dump_binary_path' => dirname($path)]);
        }
    }
}
