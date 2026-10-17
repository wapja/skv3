<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Console\Commands\BackupRunCommand;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Artisan;

/**
 * Before backup:run, prepares pg_dump.
 *
 * It fills an empty `database.connections.pgsql.dump.dump_binary_path` with the directory of the pg_dump the doctor
 * finds (RL-4, CD-6): PG_DUMP_BINARY_PATH, then for a server on this machine Herd's newest trusted PostgreSQL service
 * before the toolchain PATH, else the PATH first (PostgresBinaries). A cron job has no Herd PATH, so without this the
 * nightly backup would not find Herd's pg_dump.
 *
 * passSslMode() passes the sources' strictest sslmode as PGSSLMODE (L-05), because pg_dump reads its TLS mode from
 * the environment, not from the Laravel connection. BackupRunCommand calls it once --config and --db-name are known
 * (L7b-68): this listener runs before handle() and would read the default configuration's sources. Should the
 * package's command hold the name again (as RefuseUnencryptedBackups and PrivateBackupTemp guard against), this
 * listener exports the default sources' mode itself, as before, so pg_dump never falls back to libpq's `prefer`.
 */
final readonly class ConfigurePgDump
{
    /** libpq's sslmode values, weakest first. */
    private const array SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

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

        // L7b-68: BackupRunCommand exports PGSSLMODE once --config and --db-name are known; should the package's
        // command hold the name again, this backstop exports the default sources' mode, as before (L-05).
        if (! Artisan::findCommand('backup:run') instanceof BackupRunCommand) {
            $this->passSslMode();
        }

        if ((string) config('database.connections.pgsql.dump.dump_binary_path', '') !== '') {
            return;
        }

        $path = new PostgresBinaries($this->herdServices)->find(DoctorContext::withoutDetection($this->basePath), 'pg_dump');

        if ($path !== null) {
            config(['database.connections.pgsql.dump.dump_binary_path' => dirname($path)]);
        }
    }

    /**
     * L-05: the strictest sslmode of the PostgreSQL backup sources, each read as the app's own connection uses it: a
     * url's query overrides the sslmode key, so the connection is read through ConfigurationUrlParser, as
     * DatabaseManager and the package's DbDumperFactory read it. spatie's BackupJob runs every source's pg_dump in
     * this one process, so they all inherit one PGSSLMODE: the strictest mode (SSL_MODES) is exported, so a weaker
     * source listed first never leaves a verify-full source's certificate unchecked. A mode libpq does not know ranks
     * above them all, so pg_dump refuses it instead of the run quietly using a weaker one. A source without an
     * sslmode adds none. The package's Symfony Process inherits the getenv() keys that $_SERVER also holds, plus
     * every $_ENV key, so all three are set. The sources are the running configuration's (`--config` has swapped
     * config('backup') by then), narrowed to $only as `--db-name` narrows the dump (L7b-68).
     *
     * @param  list<string>  $only  the connections backup:run --db-name names; empty for every source
     */
    public function passSslMode(array $only = []): void
    {
        $sources = config('backup.backup.source.databases', []);
        $strictest = null;

        foreach (is_array($sources) ? $sources : [] as $connection) {
            if ($only !== [] && ! in_array($connection, $only, true)) {
                continue;
            }

            $settings = is_string($connection) ? config('database.connections.'.$connection) : null;

            if (! is_array($settings)) {
                continue;
            }

            $settings = new ConfigurationUrlParser()->parseConfiguration($settings);

            if (($settings['driver'] ?? null) !== 'pgsql') {
                continue;
            }

            $mode = $settings['sslmode'] ?? null;

            if (is_string($mode) && $mode !== '' && ($strictest === null || $this->strength($mode) > $this->strength($strictest))) {
                $strictest = $mode;
            }
        }

        if ($strictest !== null) {
            putenv('PGSSLMODE='.$strictest);
            $_SERVER['PGSSLMODE'] = $_ENV['PGSSLMODE'] = $strictest;
        }
    }

    /** The mode's place in SSL_MODES; a mode libpq does not know ranks above every known one. */
    private function strength(string $mode): int
    {
        $position = array_search($mode, self::SSL_MODES, true);

        return $position === false ? count(self::SSL_MODES) : $position;
    }
}
