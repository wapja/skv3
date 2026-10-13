<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\GitStep;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

/** §5.4, §7.4: git runs the kit's pre-commit hook (core.hooksPath = .githooks); the fix sets it as setup step 6 does. */
final readonly class GitHooksCheck implements Check
{
    public function key(): string
    {
        return 'git-hooks';
    }

    public function label(): string
    {
        return __('Git hooks');
    }

    public function run(DoctorContext $context): CheckResult
    {
        // A worktree has a .git file instead of a directory (DEC-25).
        if (! file_exists($context->path('.git'))) {
            return CheckResult::skipped(__('This is not a git repository'));
        }

        try {
            $hooksPath = trim($context->process()->run([$context->toolchain->which('git') ?? 'git', 'config', '--get', 'core.hooksPath'])->output());
        } catch (ProcessTimedOutException) {
            $hooksPath = '';
        }

        if ($hooksPath === '.githooks') {
            return CheckResult::pass(__('Git uses the kit hooks'));
        }

        return CheckResult::warn(__('Git does not use the kit hooks'), 'git config core.hooksPath .githooks');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        if (! file_exists($context->path('.git'))) {
            return null;
        }

        new GitStep($context->toolchain)->run($context->setupContext());
        $context->forgetSync();

        return $this->run($context);
    }
}
