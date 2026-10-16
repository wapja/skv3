<?php

declare(strict_types=1);

namespace App\Console\Support;

/** The real check: stream_isatty() on the command's input stream (L-54). */
final readonly class StreamTerminal implements Terminal
{
    public function isTerminal(mixed $stream): bool
    {
        return is_resource($stream) && stream_isatty($stream);
    }
}
