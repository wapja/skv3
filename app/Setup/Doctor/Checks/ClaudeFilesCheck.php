<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: when Claude was chosen, CLAUDE.md includes AGENTS.md and boost:install wrote AGENTS.md. */
final readonly class ClaudeFilesCheck implements Check
{
    public function key(): string
    {
        return 'claude-files';
    }

    public function label(): string
    {
        return __('Claude files');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->answer('claude') !== true) {
            return CheckResult::skipped(__('Claude was not chosen'));
        }

        $claude = $context->path('CLAUDE.md');

        if (is_file($claude) && str_contains((string) file_get_contents($claude), '@AGENTS.md') && is_file($context->path('AGENTS.md'))) {
            return CheckResult::pass(__('CLAUDE.md includes AGENTS.md'));
        }

        return CheckResult::fail(__('CLAUDE.md does not include AGENTS.md, or AGENTS.md is missing'), 'php artisan boost:install');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
