<?php

declare(strict_types=1);

namespace App\Setup;

/** The environment as setup found it before asking anything (§5.3.2, RL-4): one immutable snapshot. */
final readonly class Detection
{
    /**
     * @param  string  $host  the CLI host, e.g. "myapp.test" or "localhost"
     * @param  string  $scheme  "http" until the Herd step secures the site
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
}
