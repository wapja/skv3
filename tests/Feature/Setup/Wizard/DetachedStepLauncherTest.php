<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Support\Toolchain;
use App\Setup\Wizard\DetachedStepLauncher;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('starts skv:run-step detached with every value as its own argument, its shell writing its pid first (RL-4, RL-8)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    $started = new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')
        ->start($context->basePath, 'frontend', '/r.json', '/l.log', '/e.exit', '/s.started');

    expect($started)->toBeTrue();
    Process::assertRan(fn ($process): bool => $process->command[0] === 'sh'
        && $process->command[1] === '-c'
        && str_contains($process->command[2], 'nohup')
        && str_contains($process->command[2], 'echo $$ > "$5"; "$0" artisan skv:run-step')
        && array_slice($process->command, 3) === ['sh', '/usr/bin/php', 'frontend', '/r.json', '/l.log', '/e.exit', '/s.started']);
});

it('writes the pid of a launch without a pid file to /dev/null (RL-8)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')->start($context->basePath, 'herd-site', '/r.json', '/l.log', '/e.exit');

    Process::assertRan(fn ($process): bool => array_slice($process->command, -1) === ['/dev/null']);
});

it('reports a launch it could not start (RL-4)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result(exitCode: 127)]);

    expect(new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')->start($context->basePath, 'frontend', '/r', '/l', '/e'))->toBeFalse();
});

it('creates the child\'s log and exit files private: umask 077 before any redirect (g3-16)', function (): void {
    $context = setupSandbox();
    Process::fake(["*sh' '-c'*" => Process::result('')]);

    new DetachedStepLauncher(app(Toolchain::class), php: '/usr/bin/php')->start($context->basePath, 'frontend', '/r.json', '/l.log', '/e.exit');

    Process::assertRan(function (PendingProcess $process): bool {
        $script = ((array) $process->command)[2] ?? null;

        return is_string($script) && str_starts_with($script, "nohup sh -c 'umask 077; ");
    });
});
