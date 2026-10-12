<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Steps\GitStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('initialises a repository and sets the hooks path (§5.3.3 step 6)', function (): void {
    $context = setupSandbox();
    Process::fake(["*git' 'init'*" => Process::result(''), "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result('')]);

    expect(new GitStep()->run($context)->status)->toBe('done');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'git init')
        && $process->path === $context->basePath);
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'core.hooksPath .githooks')
        && $process->path === $context->basePath);
});

it('keeps an existing repository, including a worktree .git file (DEC-25)', function (): void {
    $context = setupSandbox();
    file_put_contents($context->path('.git'), "gitdir: /elsewhere\n");
    Process::fake(["*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result('')]);

    new GitStep()->run($context);

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'git init'));
});

it('is complete once a repository uses the kit hooks (§5.3.1)', function (string $hooksPath, int $exitCode, bool $complete): void {
    $context = setupSandbox();
    File::ensureDirectoryExists($context->path('.git'));
    Process::fake(["*git' 'config' '--get' 'core.hooksPath'*" => Process::result(output: $hooksPath, exitCode: $exitCode)]);

    expect(new GitStep()->isComplete($context))->toBe($complete);
})->with([
    'kit hooks' => [".githooks\n", 0, true],
    'other hooks' => [".husky\n", 0, false],
    'not set' => ['', 1, false],
]);

it('is not complete without a repository (§5.3.1)', function (): void {
    expect(new GitStep()->isComplete(setupSandbox()))->toBeFalse();

    Process::assertNothingRan();
});

it('returns the git commands as a manual step when git cannot run (§5.3.1)', function (): void {
    $context = setupSandbox();
    Process::fake(["*git' 'init'*" => Process::result(errorOutput: "xcode-select: note: No developer tools were found, requesting install.\nmore", exitCode: 1)]);

    $result = new GitStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('git init && git config core.hooksPath .githooks')
        ->and($result->message)->toBe('xcode-select: note: No developer tools were found, requesting install.');
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'core.hooksPath .githooks'));
});

it('returns only the hooks command as a manual step when an existing repository cannot be configured (§5.3.1)', function (): void {
    $context = setupSandbox();
    File::ensureDirectoryExists($context->path('.git'));
    Process::fake(["*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(exitCode: 127)]);

    $result = new GitStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('git config core.hooksPath .githooks')
        ->and($result->message)->toBe(__('Git could not run (exit code :code).', ['code' => 127]));
});
