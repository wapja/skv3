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
     * A snapshot toArray() wrote (CD-15), checked for shape and values, not detected again (L7b-30, L7b-35): every key
     * present, the host as SetupHost::normalise() leaves it, the scheme http or https, each port null or 1–65535. The
     * host, scheme and port go into APP_URL and TENANCY_APEX_DOMAIN, so a file this kit did not write is refused (RL-5).
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when a key is missing or has the wrong type or value
     */
    public static function fromArray(array $data): self
    {
        $host = self::text($data, 'host');
        $scheme = self::text($data, 'scheme');

        if (SetupHost::normalise($host) !== $host) {
            throw new InvalidArgumentException('Detection: host must be a lower-case host name without a port.');
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Detection: scheme must be http or https.');
        }

        return new self(
            herd: self::boolean($data, 'herd'),
            herdPro: self::boolean($data, 'herd_pro'),
            herdMail: self::boolean($data, 'herd_mail'),
            herdSmtpPort: self::port($data, 'herd_smtp_port'),
            postgres: self::boolean($data, 'postgres'),
            fluxPro: self::boolean($data, 'flux_pro'),
            claude: self::boolean($data, 'claude'),
            host: $host,
            scheme: $scheme,
            port: self::port($data, 'port'),
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

    /**
     * A TCP port number (1-65535): the one rule the Detector writes and fromArray() reads back, so a snapshot the
     * Detector takes is never one this class refuses.
     *
     * @phpstan-assert-if-true int $value
     */
    public static function isPort(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 65535;
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when $key is missing
     */
    private static function field(array $data, string $key): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : throw new InvalidArgumentException("Detection: {$key} is missing.");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function boolean(array $data, string $key): bool
    {
        $value = self::field($data, $key);

        return is_bool($value) ? $value : throw new InvalidArgumentException("Detection: {$key} must be a boolean.");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function text(array $data, string $key): string
    {
        $value = self::field($data, $key);

        return is_string($value) ? $value : throw new InvalidArgumentException("Detection: {$key} must be a string.");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function port(array $data, string $key): ?int
    {
        $value = self::field($data, $key);

        return $value === null || self::isPort($value)
            ? $value
            : throw new InvalidArgumentException("Detection: {$key} must be a port number or null.");
    }
}
