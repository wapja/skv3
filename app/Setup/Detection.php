<?php

declare(strict_types=1);

namespace App\Setup;

use InvalidArgumentException;

/**
 * The environment as setup found it before asking anything (§5.3.2, RL-4): one immutable snapshot. The browser
 * wizard takes it once, in begin(), and keeps it in its state files (CD-15).
 */
final readonly class Detection
{
    /**
     * @param  string  $host  the CLI host, e.g. "myapp.test" or "localhost"
     * @param  string  $scheme  "http" until the Herd step secures the site
     * @param  int|null  $port  the wizard request's port without Herd, null under Herd or on the scheme's default
     *                          port, so APP_URL keeps a `php artisan serve` port (CD-15, g1-17)
     */
    public function __construct(
        public bool $herd,
        public bool $herdPro,
        public bool $herdMail,
        public ?int $herdSmtpPort,
        public bool $postgres,
        public bool $fluxPro,
        public bool $claude,
        public string $host,
        public string $scheme,
        public ?int $port = null,
    ) {}

    /** Nothing detected: every tool absent, served over http on $host (for tests). */
    public static function none(string $host = 'localhost'): self
    {
        return new self(
            herd: false,
            herdPro: false,
            herdMail: false,
            herdSmtpPort: null,
            postgres: false,
            fluxPro: false,
            claude: false,
            host: $host,
            scheme: 'http',
        );
    }

    /**
     * A snapshot toArray() wrote (CD-15): checked for shape, not detected again.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when a key is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $bool = fn (string $key): bool => is_bool($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Detection: {$key} must be a boolean.");
        $string = fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Detection: {$key} must be a string.");
        $int = fn (string $key): ?int => ($data[$key] ?? null) === null || is_int($data[$key]) ? ($data[$key] ?? null) : throw new InvalidArgumentException("Detection: {$key} must be an integer or null.");

        return new self(
            herd: $bool('herd'),
            herdPro: $bool('herd_pro'),
            herdMail: $bool('herd_mail'),
            herdSmtpPort: $int('herd_smtp_port'),
            postgres: $bool('postgres'),
            fluxPro: $bool('flux_pro'),
            claude: $bool('claude'),
            host: $string('host'),
            scheme: $string('scheme'),
            port: $int('port'),
        );
    }

    /**
     * The snapshot as WizardState and HerdState store it (CD-15).
     *
     * @return array{herd: bool, herd_pro: bool, herd_mail: bool, herd_smtp_port: int|null, postgres: bool, flux_pro: bool, claude: bool, host: string, scheme: string, port: int|null}
     */
    public function toArray(): array
    {
        return [
            'herd' => $this->herd,
            'herd_pro' => $this->herdPro,
            'herd_mail' => $this->herdMail,
            'herd_smtp_port' => $this->herdSmtpPort,
            'postgres' => $this->postgres,
            'flux_pro' => $this->fluxPro,
            'claude' => $this->claude,
            'host' => $this->host,
            'scheme' => $this->scheme,
            'port' => $this->port,
        ];
    }
}
