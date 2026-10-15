<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Scheduler\Heartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Loads routes/console.php, as the console kernel does for schedule:run.
    Artisan::call('schedule:list');
});

/** @return array<string, Event> keyed by the artisan command, or a call's name */
$events = fn (): array => collect(app(Schedule::class)->events())
    ->mapWithKeys(fn (Event $event): array => [
        $event->command !== null ? Str::after($event->command, "'artisan' ") : (string) $event->description => $event,
    ])
    ->all();

it('schedules exactly the ops jobs of §4.9 (DEC-28)', function () use ($events): void {
    expect(array_keys($events()))->toEqualCanonicalizing([
        'activitylog:clean --force', 'backup:clean', 'backup:run --only-db', 'backup:monitor',
        'queue:prune-failed', 'model:prune', 'auth:clear-resets', 'scheduler-heartbeat',
    ]);
});

it('runs each job at its time, on one server, never twice at once (§4.9)', function (string $job, string $expression, bool $overlapGuard) use ($events): void {
    $event = $events()[$job];

    expect($event->expression)->toBe($expression)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBe($overlapGuard);
})->with([
    ['activitylog:clean --force', '0 1 * * *', true],
    ['backup:clean', '30 1 * * *', true],
    ['backup:run --only-db', '0 2 * * *', true],
    ['backup:monitor', '0 3 * * *', true],
    ['queue:prune-failed', '30 3 * * *', true],
    ['model:prune', '0 4 * * *', true],
    ['auth:clear-resets', '*/15 * * * *', true],
    ['scheduler-heartbeat', '* * * * *', false],
]);

it('runs the backup jobs only while the backups module is on (§6.8)', function (bool $enabled) use ($events): void {
    useBackupsModule($enabled);
    $all = $events();

    foreach (['backup:clean', 'backup:run --only-db', 'backup:monitor'] as $job) {
        expect($all[$job]->filtersPass(app()))->toBe($enabled, $job);
    }

    expect($all['activitylog:clean --force']->filtersPass(app()))->toBeTrue();
})->with(['module on' => true, 'module off' => false]);

it('never runs an unencrypted backup, but keeps cleaning and monitoring (CD-10)', function (array $config) use ($events): void {
    useBackupsModule(true);
    config($config);
    $all = $events();

    expect($all['backup:run --only-db']->filtersPass(app()))->toBeFalse()
        ->and($all['backup:clean']->filtersPass(app()))->toBeTrue()
        ->and($all['backup:monitor']->filtersPass(app()))->toBeTrue();
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'no password' => [['backup.backup.password' => null]],
    'encryption off' => [['backup.backup.encryption' => 'none']],
    'unknown encryption' => [['backup.backup.encryption' => 'aes-256']],
]);

it('records a heartbeat every minute (RL-8)', function () use ($events): void {
    $this->travelTo(Carbon::parse('2026-10-15 12:34:00'));

    expect(app(Heartbeat::class)->last())->toBeNull();

    $events()['scheduler-heartbeat']->run(app());

    expect(app(Heartbeat::class)->last()?->toIso8601String())->toBe(Carbon::parse('2026-10-15 12:34:00')->toIso8601String());
});
