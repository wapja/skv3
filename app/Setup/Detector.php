<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\Support\HerdTld;
use App\Setup\Support\Toolchain;
use App\Support\Skv;
use Closure;
use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;
use Throwable;

/**
 * Takes the Detection snapshot before setup asks anything (§5.3.2, RL-4). Herd's exit codes are unreliable, so
 * every Herd fact comes from parsed output or Herd's own config file, never from an exit code (§13).
 */
final readonly class Detector
{
    /**
     * @param  (Closure(string, int): bool)|null  $postgresProbe  replaces the PDO probe (RL-3): tests pass one, so no
     *                                                            test opens a connection to the developer's PostgreSQL
     */
    public function __construct(private Toolchain $toolchain, private ?Closure $postgresProbe = null) {}

    /**
     * @param  string|null  $requestHost  the host of the current request, when there is one (the browser wizard); one
     *                                    that fails SetupHost::normalise() falls back to the CLI host (A4)
     * @param  string  $scheme  the scheme the site is served over now
     * @param  int|null  $requestPort  the port of the current request; kept without Herd unless it is the scheme's
     *                                 default (CD-15, g1-17)
     */
    public function detect(?string $requestHost = null, string $scheme = 'http', ?int $requestPort = null): Detection
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
            host: ($requestHost !== null ? SetupHost::normalise($requestHost) : null) ?? $this->cliHost(),
            scheme: $scheme,
            port: $herd === null && $requestPort !== ($scheme === 'https' ? 443 : 80) ? $requestPort : null,
        );
    }

    /** Whether Postgres answers with Herd's known credentials: user root, empty password, database postgres. */
    public function postgresReachable(string $host = '127.0.0.1', int $port = 5432): bool
    {
        if ($this->postgresProbe instanceof Closure) {
            return ($this->postgresProbe)($host, $port);
        }

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

    /**
     * Herd Pro lists its services as a JSON array; the free edition prints a notice instead (RL-4). A Herd that hangs
     * or fails to start counts as no Herd Pro (A5, A18).
     */
    private function herdPro(string $herd): bool
    {
        try {
            $result = Process::env($this->toolchain->environment())->timeout(5)->run([$herd, 'services:list', '--json']);
        } catch (Throwable) {
            return false;
        }

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

    /** The folder plus `herd tld` under Herd, else the APP_URL host, else localhost (RL-4, A5). */
    private function cliHost(): string
    {
        $tld = HerdTld::read($this->toolchain);

        if ($tld !== null) {
            return strtolower(basename(base_path()).'.'.$tld);
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
