<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Artisan commands, the scheduler and queue workers run centrally by default (§6.2). A plain
 * singleton, not scoped: the worker's per-job scope reset must not turn strict mode back on.
 * A job that carries a tenant still binds it, and a bound tenant always scopes. Entered on
 * CommandStarting and left on CommandFinished, so an Artisan::call() inside an HTTP request
 * (setup wizard) is central only while the command runs; queue:work and schedule:work stay
 * central until they exit.
 */
final class CentralContext
{
    private int $depth = 0;

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }
}
