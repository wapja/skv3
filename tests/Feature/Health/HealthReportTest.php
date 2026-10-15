<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\FixedStatusCheck;
use Tests\Fixtures\ThrowingCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
});

it('checks the database, the queue, failed jobs, mail and the last backup, in that order (RL-1)', function (): void {
    useBackupsModule(false);

    $report = new HealthReport(basePath: $this->sandbox->basePath)->run();

    expect(array_keys($report['checks']))->toBe(['database', 'queue', 'failed-jobs', 'mail', 'last-backup'])
        ->and($report['healthy'])->toBeTrue()
        ->and($report['checks']['last-backup']['status'])->toBe(CheckStatus::Skipped->value)
        ->and($report['checks']['database'])->toHaveKeys(['label', 'status', 'message'])
        ->and($report['checks']['database'])->not->toHaveKey('fix');
});

it('is unhealthy when one check fails, and names only a throwing check\'s exception class (RL-3)', function (): void {
    $report = new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'fine'), new ThrowingCheck], $this->sandbox->basePath)->run();

    expect($report['healthy'])->toBeFalse()
        ->and($report['checks']['throwing']['status'])->toBe('fail')
        ->and($report['checks']['throwing']['message'])->not->toContain('secret-binding');
});

it('stays healthy when a check only warns (CD-3)', function (): void {
    $report = new HealthReport([new FixedStatusCheck(CheckStatus::Warn, 'odd')], $this->sandbox->basePath)->run();

    expect($report['healthy'])->toBeTrue();
});

it('caches the report on the ops store until it is refreshed (CD-5)', function (): void {
    $report = new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'fine')], $this->sandbox->basePath);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $first = $report->cached();

    $this->travelTo(Carbon::parse('2026-10-15 10:00:10'));
    $second = $report->cached();
    $refreshed = $report->refresh();

    expect($second['checked_at'])->toBe($first['checked_at'])
        ->and($refreshed['checked_at'])->not->toBe($first['checked_at'])
        ->and(Cache::store((string) config('skv.ops_cache_store'))->get($report->key()))->toBe($refreshed);
});

it('caches one report per language, because labels and messages are translated (§6.5, CD-5)', function (): void {
    $report = new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'fine')], $this->sandbox->basePath);
    app()->setLocale('nl');
    $dutch = $report->cached();
    app()->setLocale('en');

    expect($report->key())->toBe(HealthReport::CACHE_KEY.':en')
        ->and(Cache::store((string) config('skv.ops_cache_store'))->get($report->key()))->toBeNull()
        ->and(Cache::store((string) config('skv.ops_cache_store'))->get(HealthReport::CACHE_KEY.':nl'))->toBe($dutch);
});
