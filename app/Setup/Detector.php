<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\Support\Toolchain;
use App\Support\Skv;
use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;

/**
 * Takes the Detection snapshot before setup asks anything (§5.3.2, RL-4). Herd's exit codes are unreliable, so
 * every Herd fact comes from parsed output or Herd's own config file, never from an exit code (§13).
 */
final readonly class Detector
{
    public function __construct(private Toolchain $toolchain) {}

    /**
     * @param  string|null  $requestHost  the host of the current request, when there is one (the browser wizard)
     * @param  string  $scheme  the scheme the site is served over now
     */
    public function detect(?string $requestHost = null, string $scheme = 'http'): Detection
    {
        $herd = $this->toolchain->herd();
        [$herdMail, $herdSmtpPort] = $this->herdMail();

        return new Detection(
            herd: $herd !== null,
            herdPro: $herd !== null && $this->herdPro($herd),
            herdMail: $herd !== null && $herdMail,
            herdSmtpPort: $herd !== null ? $herdSmtpPort : null,
            postgres: $this->postgresReachable(),
            fluxPro: Skv::hasFluxPro(),
            claude: $this->toolchain->which('claude') !== null,
            host: $requestHost ?? $this->cliHost($herd),
            scheme: $scheme,
        );
    }

    /** Whether Postgres answers with Herd's known credentials: user root, empty password, database postgres. */
    public function postgresReachable(string $host = '127.0.0.1', int $port = 5432): bool
    {
        try {
            new PDO("pgsql:host={$host};port={$port};dbname=postgres", 'root', '', [
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** Herd Pro lists its services as a JSON array; the free edition prints a notice instead (RL-4). */
    private function herdPro(string $herd): bool
    {
        $result = Process::env($this->toolchain->environment())->timeout(10)->run([$herd, 'services:list', '--json']);
        $services = json_decode($result->output(), true);

        return is_array($services) && array_is_list($services);
    }

    /**
     * Herd's mail service from herd.json: a truthy `mails` and an int `smtpPort`. The file is read, never printed.
     *
     * @return array{0: bool, 1: int|null}
     */
    private function herdMail(): array
    {
        $path = $this->toolchain->herdConfigPath();
        $config = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($config) || ! is_int($config['smtpPort'] ?? null) || ! (bool) ($config['mails'] ?? false)) {
            return [false, null];
        }

        return [true, $config['smtpPort']];
    }

    /** The folder plus `herd tld` under Herd, else the APP_URL host, else localhost (RL-4). */
    private function cliHost(?string $herd): string
    {
        if ($herd !== null) {
            $tld = trim(Process::env($this->toolchain->environment())->timeout(10)->run([$herd, 'tld'])->output());

            if (preg_match('/^[a-z0-9-]+$/i', $tld) === 1) {
                return strtolower(basename(base_path()).'.'.$tld);
            }
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
