<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/** `herd tld`, read one way everywhere (A5): 5 seconds at most, never throws, null unless it prints a plain label. */
final class HerdTld
{
    public static function read(Toolchain $toolchain): ?string
    {
        $herd = $toolchain->herd();

        if ($herd === null) {
            return null;
        }

        try {
            $tld = Str::lower(trim(Process::env($toolchain->environment())->timeout(5)->run([$herd, 'tld'])->output()));
        } catch (Throwable) {
            return null;
        }

        return preg_match('/^[a-z0-9-]+$/', $tld) === 1 ? $tld : null;
    }
}
