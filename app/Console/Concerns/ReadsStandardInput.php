<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Console\Support\Terminal;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Reads secrets from standard input (CD-8, RL-10), so they never appear in the process list or the shell history:
 * `printf '%s\n' "$PASSWORD" | php artisan …`. Tests set the stream with Input::setStream().
 */
trait ReadsStandardInput
{
    /** The first line of standard input without its line ending; null for an empty line or a closed stream. */
    protected function firstLineOfStandardInput(): ?string
    {
        $stream = $this->standardInput();
        $line = $stream === null ? false : fgets($stream);

        if (! is_string($line)) {
            return null;
        }

        $line = rtrim($line, "\r\n");

        return $line === '' ? null : $line;
    }

    /** All of standard input; null when it is empty. */
    protected function allOfStandardInput(): ?string
    {
        $stream = $this->standardInput();
        $contents = $stream === null ? false : stream_get_contents($stream);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    /** Whether standard input is a terminal (L-54): what someone types there shows on the screen. */
    protected function standardInputIsTerminal(): bool
    {
        $stream = $this->standardInput();

        return $stream !== null && app(Terminal::class)->isTerminal($stream);
    }

    /** @return resource|null */
    private function standardInput()
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        return $stream ?? (defined('STDIN') ? STDIN : null);
    }
}
