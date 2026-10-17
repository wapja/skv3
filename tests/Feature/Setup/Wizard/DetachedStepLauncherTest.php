<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Support\Toolchain;
use App\Setup\Wizard\DetachedStepLauncher;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\File;
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
        && str_contains($process->command[2], 'echo $$ > "$5"; ')
        && str_contains($process->command[2], '"$0" artisan skv:run-step "$1" --no-interaction --result="$2" > "$3" 2>&1')
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

it('keeps the child\'s pid, log and exit files private but lets the step create files under the umask it inherited, and writes the exit file only after the step (g3-16, G6-06)', function (): void {
    // Arrange: a stand-in for php that creates a file of its own and records whether the exit file already exists.
    $context = setupSandbox();
    $directory = $context->path(WizardState::STEPS);
    File::ensureDirectoryExists($directory);
    $php = $context->path('fake-php');
    File::put($php, "#!/bin/sh\nif [ -e '{$directory}/frontend.exit' ]; then echo early > made-by-step; else echo late > made-by-step; fi\n");
    chmod($php, 0755);
    // The real script runs (sh, nohup and the stand-in); nothing else may.
    Process::preventStrayProcesses(false);
    $umask = umask(0022);

    try {
        $started = new DetachedStepLauncher(app(Toolchain::class), php: $php)
            ->start($context->basePath, 'frontend', $directory.'/frontend.json', $directory.'/frontend.log', $directory.'/frontend.exit', $directory.'/frontend.started');
    } finally {
        umask($umask);
    }

    // The child runs detached: wait at most ten seconds for the exit code it writes last.
    $deadline = microtime(true) + 10;

    while ((! is_file($directory.'/frontend.exit') || trim((string) file_get_contents($directory.'/frontend.exit')) === '') && microtime(true) < $deadline) {
        usleep(50_000);
        clearstatcache();
    }

    expect($started)->toBeTrue()
        ->and(trim((string) file_get_contents($directory.'/frontend.exit')))->toBe('0')
        ->and(trim((string) file_get_contents($context->path('made-by-step'))))->toBe('late')
        ->and(fileperms($context->path('made-by-step')) & 0777)->toBe(0644)
        ->and(fileperms($directory.'/frontend.log') & 0777)->toBe(0600)
        ->and(fileperms($directory.'/frontend.exit') & 0777)->toBe(0600)
        ->and(fileperms($directory.'/frontend.started') & 0777)->toBe(0600);
});
