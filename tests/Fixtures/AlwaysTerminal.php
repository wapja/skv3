<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Console\Support\Terminal;

/** A terminal for every stream, so a test reaches the terminal refusal of --password-stdin (L-54): php://memory never is one. */
final readonly class AlwaysTerminal implements Terminal
{
    public function isTerminal(mixed $stream): bool
    {
        return true;
    }
}
