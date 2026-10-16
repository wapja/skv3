<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Setup\Doctor\Checks\MailCheck;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Fixtures\FixedStatusCheck;
use Tests\Fixtures\ThrowingCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    // RL-2: forApplication() starts herd processes and reads this repository's .env; web code never resolves it.
    app()->bind(function (): DoctorContext {
        throw new LogicException('Web code builds its doctor context with DoctorContext::withoutDetection().');
    });
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

it('probes the mail server the running configuration names, not the one in .env (CD-5, F10)', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    assert($server !== false);
    $port = (int) Str::afterLast((string) stream_socket_get_name($server, false), ':');
    // The sandbox .env is .env.example, which says MAIL_MAILER=log.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $port]);

    try {
        $report = new HealthReport([new MailCheck], $this->sandbox->basePath)->run();
    } finally {
        fclose($server);
    }

    expect($report['checks']['mail']['status'])->toBe(CheckStatus::Pass->value)
        ->and($report['checks']['mail']['message'])->toBe(__('The mail server answers at :address', ['address' => '127.0.0.1:'.$port]));
});
