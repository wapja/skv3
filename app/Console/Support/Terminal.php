<?php

declare(strict_types=1);

namespace App\Console\Support;

/**
 * Tells whether a stream is a terminal (L-54): a secret typed there shows on the screen, so --password-stdin refuses
 * it. Tests bind Tests\Fixtures\AlwaysTerminal, because the memory streams they pass never are one.
 */
interface Terminal
{
    /** @param  resource  $stream */
    public function isTerminal(mixed $stream): bool;
}
