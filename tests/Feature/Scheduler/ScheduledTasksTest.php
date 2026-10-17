<?php

declare(strict_types=1);

/** covers §4.9, §6.5 */

use App\Support\Scheduler\ScheduledTasks;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('lists the ops schedule from its own copy, not from the scheduler\'s instance (CD-8, L-39)', function (): void {
    // A web request has not loaded routes/console.php: an empty bound schedule stands in for that.
    app()->instance(Schedule::class, new Schedule);

    $tasks = ScheduledTasks::all();

    expect($tasks)->toHaveCount(10)
        ->and(array_column($tasks, 'expression', 'task'))->toBe([
            'activitylog:clean --force' => '0 1 * * *',
            'backup-temp-cleanup' => '15 * * * *',
            'livewire-tmp-cleanup' => '45 * * * *',
            'backup:clean' => '30 1 * * *',
            'backup:run --only-db' => '0 2 * * *',
            'backup:monitor' => '0 3 * * *',
            'queue:prune-failed' => '30 3 * * *',
            'model:prune' => '0 4 * * *',
            'auth:clear-resets' => '*/15 * * * *',
            'scheduler-heartbeat' => '* * * * *',
        ]);
});

it('reports a backup as active while its overlap lock is held, and leaves that lock alone (CD-8, F1)', function (): void {
    useBackupsModule(true);
    $running = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'backup:run'))
        ?? throw new LogicException('backup:run is not scheduled.');

    // The page's copy builds its command line without the PHP binary, so its mutex name differs from the real event's.
    $copy = collect(ScheduledTasks::schedule()->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'backup:run'))
        ?? throw new LogicException('backup:run is not listed.');

    // The scheduler holds this lock (default cache store) while the nightly backup runs. The copy's own name is held
    // too, as if a copy had the real name: only the inert mutex keeps `active` from seeing either lock.
    expect($running->mutex->create($running))->toBeTrue()
        ->and($copy->mutex->create($copy))->toBeTrue();

    $active = array_column(ScheduledTasks::all(), 'active', 'task');

    expect($active['backup:run --only-db'])->toBeTrue()
        ->and($running->mutex->exists($running))->toBeTrue()
        ->and($copy->mutex->exists($copy))->toBeTrue();

    $running->mutex->forget($running);
    $copy->mutex->forget($copy);
});

it('builds its copies without looking up the PHP binary, which forks a shell under php-fpm (CD-8, RL-7)', function (): void {
    $commands = array_values(array_filter(array_map(
        fn (Event $event): ?string => $event->command,
        ScheduledTasks::schedule()->events(),
    )));

    expect($commands)->toHaveCount(7);

    foreach ($commands as $command) {
        expect($command)->toStartWith(Application::artisanBinary().' ')
            ->and(str_contains($command, Application::phpBinary()))->toBeFalse();
    }
});
