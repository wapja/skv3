<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * CD-9: the account this PHP process runs as, for the "this account" rules of PrivateBackupTemp and PostgresBinaries.
 * Only ext-posix knows it: getmyuid() is the owner of the entry script, not the process. Without ext-posix uid() is
 * null and those rules count no directory as this account's (fail closed); the kit requires no ext-posix.
 */
final readonly class ProcessAccount
{
    /** @param  (Closure(): ?int)|null  $probe  returns the effective uid, or null when it is unknown; tests pass one */
    public function __construct(private ?Closure $probe = null) {}

    /** The effective uid of this process, or null without ext-posix. */
    public function uid(): ?int
    {
        if ($this->probe instanceof Closure) {
            return ($this->probe)();
        }

        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }
}
