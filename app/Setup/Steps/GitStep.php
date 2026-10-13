<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * §5.3.3 step 6: `git init` when there is no repository yet (a .git directory, or the .git file of a worktree,
 * DEC-25), then `git config core.hooksPath .githooks` so the kit's pre-commit hook runs. When git is missing or
 * cannot run (the macOS developer-tools shim exits 1), the step returns the commands as `manual()` (§5.3.1).
 */
final readonly class GitStep implements SetupStep
{
    private Toolchain $toolchain;

    public function __construct(?Toolchain $toolchain = null)
    {
        $this->toolchain = $toolchain ?? app(Toolchain::class);
    }

    public function key(): string
    {
        return 'git';
    }

    public function label(): string
    {
        return __('Git');
    }

    public function isComplete(SetupContext $context): bool
    {
        if (! file_exists($context->path('.git'))) {
            return false;
        }

        return trim($this->git($context, ['config', '--get', 'core.hooksPath'])->output()) === '.githooks';
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        $hooksCommand = 'git config core.hooksPath .githooks';

        if (! file_exists($context->path('.git'))) {
            $init = $this->git($context, ['init']);

            if ($init->failed()) {
                return StepResult::manual('git init && '.$hooksCommand, $this->failureReason($init));
            }
        }

        $hooks = $this->git($context, ['config', 'core.hooksPath', '.githooks']);

        if ($hooks->failed()) {
            return StepResult::manual($hooksCommand, $this->failureReason($hooks));
        }

        return StepResult::done();
    }

    /** The first non-empty line git printed, or the exit code when it printed nothing. */
    private function failureReason(ProcessResult $result): string
    {
        foreach (preg_split('/\R/', $result->errorOutput()."\n".$result->output()) ?: [] as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return __('Git could not run (exit code :code).', ['code' => $result->exitCode()]);
    }

    /** @param  list<string>  $arguments */
    private function git(SetupContext $context, array $arguments): ProcessResult
    {
        return Process::path($context->basePath)
            ->env($this->toolchain->environment())
            ->timeout(60)
            ->run([$this->toolchain->which('git') ?? 'git', ...$arguments]);
    }
}
