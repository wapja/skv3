<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Throwable;

/**
 * An error message that is safe to show (RL-13). A query error shows the driver's message only, cut before
 * PostgreSQL's DETAIL/HINT lines: its own message carries the bindings and DETAIL repeats the failing row (a
 * password hash). A failed process shows its first line. Callers escape the result for their output.
 */
final class SafeMessage
{
    public static function of(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $message = $exception->getPrevious()?->getMessage() ?? __('A database query failed.');

            return trim(preg_replace('/\R(?:DETAIL|HINT):.*/s', '', $message) ?? $message);
        }

        $message = trim($exception->getMessage());

        return $exception instanceof ProcessFailedException ? trim(strtok($message, "\n") ?: $message) : $message;
    }
}
