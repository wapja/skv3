<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * PGSSLMODE in the three places ConfigurePgDump::passSslMode() writes it (getenv(), $_SERVER, $_ENV), taken before a
 * test and put back after it (L-05, L7b-68): putenv() outlives the test, and a later pg_dump or psql would inherit it.
 */
final readonly class PgSslModeSnapshot
{
    private function __construct(private string|false $getenv, private mixed $server, private mixed $env) {}

    /** Takes the current values and clears all three, so the test starts without PGSSLMODE. */
    public static function takeAndClear(): self
    {
        $snapshot = new self(getenv('PGSSLMODE'), $_SERVER['PGSSLMODE'] ?? null, $_ENV['PGSSLMODE'] ?? null);
        putenv('PGSSLMODE');
        unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);

        return $snapshot;
    }

    public function restore(): void
    {
        putenv($this->getenv === false ? 'PGSSLMODE' : 'PGSSLMODE='.$this->getenv);
        unset($_SERVER['PGSSLMODE'], $_ENV['PGSSLMODE']);

        if ($this->server !== null) {
            $_SERVER['PGSSLMODE'] = $this->server;
        }

        if ($this->env !== null) {
            $_ENV['PGSSLMODE'] = $this->env;
        }
    }
}
