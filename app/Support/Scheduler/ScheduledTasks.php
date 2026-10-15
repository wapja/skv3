<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

/** The schedule of routes/console.php as the System page lists it (§4.9, RL-6). */
final readonly class ScheduledTasks
{
    /** @return list<array{task: string, expression: string, next: string, active: bool}> */
    public static function all(): array
    {
        // A web request has not loaded routes/console.php; the console kernel's bootstrap requires it once.
        app(Kernel::class)->bootstrap();

        return array_values(array_map(fn (Event $event): array => [
            'task' => $event->command !== null ? trim(Str::after($event->command, 'artisan'), " '") : (string) $event->description,
            'expression' => $event->expression,
            'next' => $event->nextRunDate()->toIso8601String(),
            'active' => $event->filtersPass(app()),
        ], app(Schedule::class)->events()));
    }
}
