<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Setup\Detection;
use App\Setup\Doctor\Checks\FailedJobsCheck;
use App\Setup\Doctor\Checks\LastBackupCheck;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')), Detection::none(), null);
});

$failedJob = fn (): bool => DB::table('failed_jobs')->insert([
    'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
    'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now(),
]);

it('passes without failed jobs and warns while some are stored (CD-3)', function () use ($failedJob): void {
    $none = new FailedJobsCheck()->run($this->context);
    $failedJob();
    $one = new FailedJobsCheck()->run($this->context);

    expect($none->status)->toBe(CheckStatus::Pass)
        ->and($one->status)->toBe(CheckStatus::Warn)
        ->and($one->message)->toBe(trans_choice(':count failed job is stored|:count failed jobs are stored', 1))
        ->and($one->fix)->toBe('php artisan queue:retry all');
});

it('fails when the failed-jobs table is missing (§4.9)', function (): void {
    config(['queue.failed.table' => 'missing_failed_jobs']);

    $result = new FailedJobsCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('missing_failed_jobs')
        ->and($result->fix)->toBe('php artisan migrate');
});

it('passes when failed jobs are not stored at all (CD-3)', function (): void {
    config(['queue.failed.driver' => 'null']);

    expect(new FailedJobsCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);
});

it('skips the last backup while the backups module is off (§6.8)', function (): void {
    useBackupsModule(false);

    expect(new LastBackupCheck()->run($this->context)->status)->toBe(CheckStatus::Skipped);
});

it('fails while the backups would not be encrypted, even with a fresh backup (CD-10)', function (): void {
    useBackupsModule(true);
    config(['backup.backup.password' => null]);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->format('Y-m-d-H-i-s').'.zip', 'fresh');

    $result = new LastBackupCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty or this PHP zip has no AES-256'))
        ->and($result->fix)->toBe('php artisan skv:doctor --fix');
});

it('fails without a backup or with one older than a day, and passes with a fresh one (CD-4)', function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    $name = (string) config('backup.backup.name');
    $this->travelTo(Carbon::parse('2026-10-15 03:00:00'));

    $none = new LastBackupCheck()->run($this->context);

    Storage::disk('backups')->put($name.'/2026-10-13-02-00-00.zip', 'old');
    $stale = new LastBackupCheck()->run($this->context);

    Storage::disk('backups')->put($name.'/2026-10-15-02-00-00.zip', 'fresh');
    $fresh = new LastBackupCheck()->run($this->context);

    expect($none->status)->toBe(CheckStatus::Fail)
        ->and($none->fix)->toBe('php artisan backup:run --only-db')
        ->and($stale->status)->toBe(CheckStatus::Fail)
        ->and($fresh->status)->toBe(CheckStatus::Pass)
        // diffForHumans() follows the app locale, so the expectation is computed the same way.
        ->and($fresh->message)->toContain(Carbon::parse('2026-10-15 02:00:00')->diffForHumans());
});
