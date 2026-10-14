<?php

declare(strict_types=1);

namespace App\Setup\Support;

/** The non-interactive first run that skv:install runs as its step 0 (§5.2); a seam so tests can make it fail. */
interface BootstrapRunner
{
    /** @return list<string> the human-readable actions it took */
    public function run(): array;
}
