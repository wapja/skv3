<?php

declare(strict_types=1);

namespace App\Setup\Support;

/**
 * One value in a command the kit prints for the user to run (RL-6, g2-05): a manual step command, a doctor fix, a
 * cleanup line. A value of characters no shell treats specially stays as it is, so ordinary folder names stay
 * readable; anything else is single-quoted by escapeshellarg(). Process argv never goes through here: it passes every
 * value as its own argument.
 */
final class ShellArgument
{
    /** `\z`, not `$`, so a trailing newline is quoted; no leading `=`, which zsh expands to a command's path. */
    private const string PLAIN = '/\A[A-Za-z0-9._\/:@%+-][A-Za-z0-9._\/:=@%+-]*\z/';

    public static function quote(string $value): string
    {
        return preg_match(self::PLAIN, $value) === 1 ? $value : escapeshellarg($value);
    }
}
