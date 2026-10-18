<?php

declare(strict_types=1);

namespace App\Setup\Support;

use App\Setup\SetupToken;
use App\Setup\Steps\EnvironmentStep;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;

/**
 * The non-interactive first run (§5.2, RL-1): .env, APP_KEY, the Herd APP_URL, the SQLite file plus migrate and the
 * one-time setup token (RL-10). In production (an APP_ENV outside local, testing and staging, or none in any source,
 * CD-12) it only creates `.env`, `APP_KEY` and the blank `EnvironmentStep::GENERATED` secrets (CD-3, CD-9, CD-10).
 * Never chooses a database, never prompts, never caches config or routes. skv:install runs it as its step 0.
 */
final readonly class Bootstrap implements BootstrapRunner
{
    /** The environments that get the development half; skv:install runs only in these (CD-3). */
    private const array DEVELOPMENT = ['local', 'testing', 'staging'];

    public function __construct(private string $basePath, private Toolchain $toolchain) {}

    /** @return list<string> the human-readable actions it took */
    public function run(): array
    {
        $actions = [];
        $env = new EnvFile($this->basePath.'/.env');

        if (! $env->exists() && is_file($this->basePath.'/.env.example')) {
            // Atomically, so a symlinked .env is replaced, never written through (RL-1); 0600 whatever the example's
            // mode, because APP_KEY and the generated secrets go into it next (CD-8). An example that cannot be read
            // stops here instead of leaving an empty .env (L7b-03).
            StateFile::write($this->basePath.'/.env', new EnvFile($this->basePath.'/.env.example')->contents(), 0600);
            $actions[] = __('Created .env from .env.example');
        }

        if (($env->get('APP_KEY') ?? '') === '') {
            $env->set(['APP_KEY' => 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')))]);
            $actions[] = __('Generated an application key');
        }

        // CD-9: a production install gets its .env and key only; no Herd address, no SQLite, no setup token.
        // CD-10: it does get the secrets setup would generate, because a blank BACKUP_ARCHIVE_PASSWORD means
        // unencrypted backups. Only the key names are reported, never the values.
        if ($this->isProduction()) {
            $secrets = EnvironmentStep::missingSecrets($env);

            if ($secrets !== []) {
                $env->set($secrets);
            }

            foreach (array_keys($secrets) as $key) {
                $actions[] = __('Generated :key', ['key' => $key]);
            }

            return $actions;
        }

        if (preg_match('#^http://localhost(:8000)?/?$#', (string) $env->get('APP_URL')) === 1) {
            $tld = HerdTld::read($this->toolchain);

            if ($tld !== null) {
                $env->set(['APP_URL' => 'http://'.strtolower(basename($this->basePath)).'.'.$tld]);
                $actions[] = __('Set APP_URL to the Herd address');
            }
        }

        if (($env->get('DB_CONNECTION') ?? 'sqlite') === 'sqlite') {
            $database = $this->basePath.'/database/database.sqlite';

            if (! is_file($database)) {
                touch($database);
                $actions[] = __('Created the SQLite database');
            }

            Process::path($this->basePath)->env($this->toolchain->environment())->timeout(300)
                ->run([PHP_BINARY, 'artisan', 'migrate', '--graceful', '--force', '--no-interaction'])
                ->throw();
            $actions[] = __('Migrated the database');
        }

        if (new SetupToken($this->basePath)->ensure()) {
            $actions[] = __('Created the one-time setup token');
        }

        return $actions;
    }

    /**
     * Whether the project this Bootstrap prepares is a production install (CD-3): its .env, or the process environment,
     * names an APP_ENV outside local, testing and staging, the environments skv:install runs in (`prod` and `live`
     * count too). A server's own APP_ENV overrides the file, and the running app's loaded .env shows there too. When
     * no source names one, it is production too, as Laravel's own default is (config/app.php, CD-12): run() copies
     * .env.example (APP_ENV=local) first, so only a .env written without APP_ENV gets here (C37).
     */
    public function isProduction(): bool
    {
        $environments = array_filter(
            [new EnvFile($this->basePath.'/.env')->get('APP_ENV'), getenv('APP_ENV'), $_SERVER['APP_ENV'] ?? null],
            fn (mixed $environment): bool => is_string($environment) && $environment !== '',
        );

        return $environments === [] || array_any($environments, fn (string $environment): bool => ! in_array($environment, self::DEVELOPMENT, true));
    }

    /** The `APP_URL` of the project this Bootstrap prepares (RL-10), never the running app's own `.env`. */
    public function appUrl(): string
    {
        return (string) (new EnvFile($this->basePath.'/.env')->get('APP_URL') ?? config('app.url'));
    }
}
