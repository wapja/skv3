<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Setup\Detection;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    // Never the repository's own .env, marker or .mcp.json: every DoctorCommand test runs on a sandbox.
    $sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($sandbox->path('home')));
    app()->instance(DoctorContext::class, new DoctorContext($sandbox->basePath, new Toolchain($sandbox->path('home')), Detection::none(), null));
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

it('runs as a composer script (§7.4)', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['doctor'] ?? null)->toBe(['@php artisan skv:doctor --ansi']);
});
