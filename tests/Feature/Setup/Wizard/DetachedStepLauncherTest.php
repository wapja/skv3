<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Support\Toolchain;
use App\Setup\Wizard\DetachedStepLauncher;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('starts skv:run-step detached with every value as its own argument (RL-4)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    $started = new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')
        ->start($context->basePath, 'frontend', '/r.json', '/l.log', '/e.exit');

    expect($started)->toBeTrue();
    Process::assertRan(fn ($process): bool => $process->command[0] === 'sh'
        && $process->command[1] === '-c'
        && str_contains($process->command[2], 'nohup')
        && array_slice($process->command, 3) === ['sh', '/usr/bin/php', 'frontend', '/r.json', '/l.log', '/e.exit']);
});

it('reports a launch it could not start (RL-4)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result(exitCode: 127)]);

    expect(new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')->start($context->basePath, 'frontend', '/r', '/l', '/e'))->toBeFalse();
});
