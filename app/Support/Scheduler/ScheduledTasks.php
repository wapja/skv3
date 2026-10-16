<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * The ops schedule as the System page lists it (§4.9, RL-6, CD-8). It defines OpsSchedule on a fresh Schedule (in the
 * timezone the console kernel uses), so a render never boots the console kernel, and gives every copy an inert mutex,
 * so `active` never takes or releases the real overlap lock of a running job (F1).
 */
final readonly class ScheduledTasks
{
    /** @return list<array{task: string, expression: string, next: string, active: bool}> in OpsSchedule's order */
    public static function all(): array
    {
        $schedule = new Schedule(config('app.schedule_timezone', config('app.timezone')));
        OpsSchedule::define($schedule);
        $mutex = new InertEventMutex;

        return array_values(array_map(fn (Event $event): array => [
            'task' => $event->command !== null ? trim(Str::after($event->command, 'artisan'), " '") : (string) $event->description,
            'expression' => $event->expression,
            'next' => $event->nextRunDate()->toIso8601String(),
            'active' => $event->preventOverlapsUsing($mutex)->filtersPass(app()),
        ], $schedule->events()));
    }
}
