<?php

declare(strict_types=1);

/** covers §5.3.1 */

use App\Setup\StepResult;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('carries the command and reason of a manual step (§5.3.1)', function (): void {
    $manual = StepResult::manual('npm ci && npm run build', 'Node is missing');

    expect($manual->isManual())->toBeTrue()
        ->and($manual->command)->toBe('npm ci && npm run build')
        ->and($manual->message)->toBe('Node is missing')
        ->and(StepResult::done()->isManual())->toBeFalse()
        ->and(StepResult::skipped('not needed')->status)->toBe('skipped');
});
