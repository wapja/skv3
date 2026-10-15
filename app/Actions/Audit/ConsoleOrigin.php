<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use Illuminate\Support\Facades\Context;

/**
 * Which Artisan command is running, for the audit log (CD-7, RL-9). Hidden context: never written to the logs,
 * carried into the jobs a command dispatches, and replaced per job by a queue worker, so a worker's jobs keep the
 * origin they were dispatched with. A nested Artisan::call() keeps the outer command.
 */
final class ConsoleOrigin
{
    public const string CONTEXT_KEY = 'audit.console_command';

    /** @var list<string> commands that only run other work; they are never an entry's origin */
    public const array WORKERS = [
        'queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'schedule:test', 'horizon', 'horizon:work', 'octane:start',
    ];

    public static function enter(?string $command): void
    {
        if ($command === null || $command === '' || in_array($command, self::WORKERS, true) || self::command() !== null) {
            return;
        }

        Context::addHidden(self::CONTEXT_KEY, $command);
    }

    public static function leave(?string $command): void
    {
        if ($command !== null && self::command() === $command) {
            Context::forgetHidden(self::CONTEXT_KEY);
        }
    }

    public static function command(): ?string
    {
        $command = Context::getHidden(self::CONTEXT_KEY);

        return is_string($command) ? $command : null;
    }
}
