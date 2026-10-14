<?php

declare(strict_types=1);

namespace App\Setup\Support;

use App\Setup\SetupToken;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;

/**
 * The non-interactive first run (§5.2, RL-1): .env, APP_KEY, the Herd APP_URL, the SQLite file plus migrate and the
 * one-time setup token (RL-10).
 * Never chooses a database, never prompts, never caches config or routes. skv:install runs it as its step 0.
 */
final readonly class Bootstrap implements BootstrapRunner
{
    public function __construct(private string $basePath, private Toolchain $toolchain) {}

    /** @return list<string> the human-readable actions it took */
    public function run(): array
    {
        $actions = [];
        $env = new EnvFile($this->basePath.'/.env');

        if (! $env->exists() && is_file($this->basePath.'/.env.example')) {
            copy($this->basePath.'/.env.example', $this->basePath.'/.env');
            $actions[] = __('Created .env from .env.example');
        }

        if (($env->get('APP_KEY') ?? '') === '') {
            $env->set(['APP_KEY' => 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')))]);
            $actions[] = __('Generated an application key');
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

    /** The `APP_URL` of the project this Bootstrap prepares (RL-10), never the running app's own `.env`. */
    public function appUrl(): string
    {
        return (string) (new EnvFile($this->basePath.'/.env')->get('APP_URL') ?? config('app.url'));
    }
}
