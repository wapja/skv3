<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

/**
 * The ops schedule as the System page lists it (§4.9, RL-6, CD-8). It defines OpsSchedule on a fresh ListingSchedule
 * (in the timezone the console kernel uses), so a render never boots the console kernel and never forks a shell to find
 * the PHP binary (RL-7), and gives every copy an inert mutex, so `active` never takes or releases the real overlap lock
 * of a running job (F1).
 */
final readonly class ScheduledTasks
{
    /** @return list<array{task: string, expression: string, next: string, active: bool}> in OpsSchedule's order */
    public static function all(): array
    {
        $schedule = self::schedule();
        $mutex = new InertEventMutex;

        return array_values(array_map(fn (Event $event): array => [
            'task' => $event->command !== null ? trim(Str::after($event->command, 'artisan'), " '") : (string) $event->description,
            'expression' => $event->expression,
            // The app clock, as the page's diffForHumans() reads it: the default 'now' is cron-expression's own clock.
            'next' => $event->nextRunDate(now())->toIso8601String(),
            'active' => $event->preventOverlapsUsing($mutex)->filtersPass(app()),
        ], $schedule->events()));
    }

    /** The page's copy of the ops schedule, on a ListingSchedule, so building it never looks up the PHP binary. */
    public static function schedule(): ListingSchedule
    {
        $schedule = new ListingSchedule(config('app.schedule_timezone', config('app.timezone')));
        OpsSchedule::define($schedule);

        return $schedule;
    }
}
