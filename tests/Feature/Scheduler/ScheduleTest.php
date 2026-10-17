<?php

declare(strict_types=1);

/** covers §4.9.4 */

use App\Support\Scheduler\Heartbeat;
use App\Support\Scheduler\ScheduledTasks;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

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

/** @return list<string> every scheduled event's identifier, duplicates kept (L-31) */
$identifiers = fn (): array => array_values(array_map(
    fn (Event $event): string => $event->command !== null ? Str::after($event->command, "'artisan' ") : (string) $event->description,
    app(Schedule::class)->events(),
));

it('schedules exactly the ops jobs of §4.9, each once (DEC-28, L-31, CD-10, CD-4)', function () use ($identifiers): void {
    expect($identifiers())->toHaveCount(10)->toEqualCanonicalizing([
        'activitylog:clean --force', 'backup-temp-cleanup', 'livewire-tmp-cleanup', 'backup:clean', 'backup:run --only-db',
        'backup:monitor', 'queue:prune-failed', 'model:prune', 'auth:clear-resets', 'scheduler-heartbeat',
    ]);
});

it('lists on the System page exactly what the scheduler runs, in its order (CD-8)', function () use ($identifiers): void {
    expect(array_column(ScheduledTasks::all(), 'task'))->toBe($identifiers());
});

it('runs each job at its time, on the right servers, never twice at once (§4.9, CD-10, CD-4)', function (string $job, string $expression, bool $oneServer, bool $overlapGuard, int $lockMinutes) use ($events): void {
    $event = $events()[$job];

    expect($event->expression)->toBe($expression)
        ->and($event->onOneServer)->toBe($oneServer)
        ->and($event->withoutOverlapping)->toBe($overlapGuard)
        ->and($event->expiresAt)->toBe($lockMinutes);
})->with([
    ['activitylog:clean --force', '0 1 * * *', true, true, 1440],
    // Each server empties its own temporary directory, every hour.
    ['backup-temp-cleanup', '15 * * * *', false, false, 1440],
    // Livewire's temporary uploads sit on each server's own disk too; clear of backup-temp-cleanup's :15 (CD-4).
    ['livewire-tmp-cleanup', '45 * * * *', false, false, 1440],
    // A killed backup's lock expires before the next night (F6).
    ['backup:clean', '30 1 * * *', true, true, 720],
    ['backup:run --only-db', '0 2 * * *', true, true, 720],
    ['backup:monitor', '0 3 * * *', true, true, 720],
    ['queue:prune-failed', '30 3 * * *', true, true, 1440],
    ['model:prune', '0 4 * * *', true, true, 1440],
    ['auth:clear-resets', '*/15 * * * *', true, true, 1440],
    ['scheduler-heartbeat', '* * * * *', true, false, 1440],
]);

it('runs the backup jobs only while the backups module is on (§6.8)', function (bool $enabled) use ($events): void {
    useBackupsModule($enabled);
    $all = $events();

    foreach (['backup:clean', 'backup:run --only-db', 'backup:monitor'] as $job) {
        expect($all[$job]->filtersPass(app()))->toBe($enabled, $job);
    }

    expect($all['activitylog:clean --force']->filtersPass(app()))->toBeTrue();

    // F18: a dump left from before the module was switched off must still go.
    expect($all['backup-temp-cleanup']->filtersPass(app()))->toBeTrue()
        ->and($all['livewire-tmp-cleanup']->filtersPass(app()))->toBeTrue();
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

it('records a heartbeat every minute and keeps it until the next one (RL-8, L-32)', function () use ($events): void {
    $this->travelTo(Carbon::parse('2026-10-15 12:34:00'));

    expect(app(Heartbeat::class)->last())->toBeNull();

    $events()['scheduler-heartbeat']->run(app());

    expect(app(Heartbeat::class)->last()?->toIso8601String())->toBe(Carbon::parse('2026-10-15 12:34:00')->toIso8601String());

    // Stored forever: a missing cron entry shows the old beat, never "has not run yet".
    $this->travel(10)->years();

    expect(app(Heartbeat::class)->last()?->toIso8601String())->toBe(Carbon::parse('2026-10-15 12:34:00')->toIso8601String());
});

it('empties the stale files of the backup temp directory (CD-10, F18)', function () use ($events): void {
    $temp = setupSandbox()->path('backup-temp');
    config(['backup.backup.temporary_directory' => $temp]);
    File::ensureDirectoryExists($temp.'/temp/db-dumps');
    File::put($temp.'/temp/db-dumps/database.sql', 'plaintext');
    touch($temp.'/temp/db-dumps/database.sql', now()->subHours(7)->getTimestamp());

    $events()['backup-temp-cleanup']->run(app());

    expect(is_file($temp.'/temp/db-dumps/database.sql'))->toBeFalse();
});

it('empties the stale files of Livewire\'s temporary upload directory (E5, CD-4)', function () use ($events): void {
    $disk = FileUploadConfiguration::storage();
    $stale = FileUploadConfiguration::path('abandoned-photo.jpg');
    $fresh = FileUploadConfiguration::path('open-tab.csv');
    $disk->put($stale, 'a photo with its EXIF data');
    $disk->put($fresh, 'a list of addresses');
    touch($disk->path($stale), now()->subHours(25)->getTimestamp());

    $events()['livewire-tmp-cleanup']->run(app());

    expect($disk->exists($stale))->toBeFalse()
        ->and($disk->exists($fresh))->toBeTrue();

    $disk->delete($fresh);
});
