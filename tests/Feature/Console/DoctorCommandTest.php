<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Enums\CheckStatus;
use App\Setup\Detection;
use App\Setup\Doctor\Checks\HealthCheckKeyCheck;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\FixedStatusCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    // Never the repository's own .env, marker or .mcp.json: every DoctorCommand test runs on a sandbox.
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    app()->instance(DoctorContext::class, new DoctorContext($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')), Detection::none(), null));
});

it('prints JSON and exits non-zero when a check fails (§5.4, RL-11)', function (): void {
    config(['session.driver' => 'file']);

    expect(Artisan::call('skv:doctor', ['--json' => true]))->toBe(1);

    $output = Artisan::output();
    /** @var array{checks: list<array{key: string, status: string, message: string, fix: ?string}>, green: bool} $report */
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($output)->toContain('"key": "session-driver"')->toContain('"green": false')
        ->and($report['green'])->toBeFalse()
        ->and(collect($report['checks'])->firstWhere('key', 'session-driver'))->toBe([
            'key' => 'session-driver',
            'status' => 'fail',
            'message' => __('The session driver is :driver', ['driver' => 'file']),
            'fix' => __('Set SESSION_DRIVER=database in your .env'),
        ]);
});

it('lists each check with its status and fix command (§5.4)', function (): void {
    config(['session.driver' => 'file']);

    expect(Artisan::call('skv:doctor'))->toBe(1)
        ->and(Artisan::output())->toContain(__('Session driver'))
        ->toContain(__('Set SESSION_DRIVER=database in your .env'))
        ->toContain(__('Application key'));
});

it('repairs with --fix and only with --fix (§5.4, RL-11, g2-20)', function (): void {
    // A doctor of one file-only check: --fix on the kit's whole list would run npm, git and artisan repairs.
    app()->instance(Doctor::class, new Doctor([new HealthCheckKeyCheck]));
    $env = new EnvFile($this->sandbox->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => '']);

    $plain = Artisan::call('skv:doctor', ['--json' => true]);
    $untouched = $env->get('HEALTH_CHECK_KEY');
    $fixed = Artisan::call('skv:doctor', ['--json' => true, '--fix' => true]);
    /** @var array{checks: list<array{key: string, status: string, message: string, fix: ?string}>, green: bool} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($plain)->toBe(1)
        ->and($untouched)->toBe('')
        ->and($fixed)->toBe(0)
        ->and($report['green'])->toBeTrue()
        ->and($report['checks'][0]['key'])->toBe('health-check-key')
        ->and($report['checks'][0]['status'])->toBe('pass')
        ->and(strlen((string) $env->get('HEALTH_CHECK_KEY')))->toBe(40);
});

it('exits zero when nothing fails, also with --json (§5.4, RL-11, g2-22)', function (): void {
    app()->instance(Doctor::class, new Doctor([new FixedStatusCheck(CheckStatus::Pass, 'probe'), new FixedStatusCheck(CheckStatus::Warn, 'odd')]));

    $json = Artisan::call('skv:doctor', ['--json' => true]);
    /** @var array{green: bool} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $text = Artisan::call('skv:doctor');

    expect($json)->toBe(0)
        ->and($report['green'])->toBeTrue()
        ->and($text)->toBe(0)
        ->and(Artisan::output())->toContain(__('All checks pass.'));
});

it('runs as a composer script (§7.4)', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['doctor'] ?? null)->toBe(['@php artisan skv:doctor --ansi']);
});
