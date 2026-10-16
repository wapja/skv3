<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use Illuminate\Console\Events\CommandStarting;

/**
 * Before backup:run, prepares pg_dump.
 *
 * It fills an empty `database.connections.pgsql.dump.dump_binary_path` with the directory of the pg_dump the doctor
 * finds (RL-4, CD-6): PG_DUMP_BINARY_PATH, then for a server on this machine Herd's newest trusted PostgreSQL service
 * before the toolchain PATH, else the PATH first (PostgresBinaries). A cron job has no Herd PATH, so without this the
 * nightly backup would not find Herd's pg_dump.
 *
 * It also passes the source connection's sslmode as PGSSLMODE (L-05), because pg_dump reads its TLS mode from the
 * environment, not from the Laravel connection.
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
        if ($event->command !== 'backup:run') {
            return;
        }

        $this->passSslMode();

        if ((string) config('database.connections.pgsql.dump.dump_binary_path', '') !== '') {
            return;
        }

        $path = new PostgresBinaries($this->herdServices)->find(DoctorContext::withoutDetection($this->basePath), 'pg_dump');

        if ($path !== null) {
            config(['database.connections.pgsql.dump.dump_binary_path' => dirname($path)]);
        }
    }

    /**
     * L-05: the first PostgreSQL backup source's sslmode, as the app's own connection uses it. The package's Symfony
     * Process inherits the getenv() keys that $_SERVER also holds, plus every $_ENV key, so all three are set.
     */
    private function passSslMode(): void
    {
        $sources = config('backup.backup.source.databases', []);

        foreach (is_array($sources) ? $sources : [] as $connection) {
            if (! is_string($connection) || config('database.connections.'.$connection.'.driver') !== 'pgsql') {
                continue;
            }

            $mode = config('database.connections.'.$connection.'.sslmode');

            if (is_string($mode) && $mode !== '') {
                putenv('PGSSLMODE='.$mode);
                $_SERVER['PGSSLMODE'] = $_ENV['PGSSLMODE'] = $mode;
            }

            return;
        }
    }
}
