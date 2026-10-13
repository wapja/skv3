<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\ClaudeStep;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

/**
 * §5.4: when Claude was chosen and `claude` is on the PATH, `.claude/settings.json` enables the superpowers plugin and
 * `claude plugin list --json` lists it. Only warns: the kit works without the plugin.
 */
final readonly class SuperpowersCheck implements Check
{
    public function key(): string
    {
        return 'superpowers';
    }

    public function label(): string
    {
        return __('Superpowers plugin');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->answer('claude') !== true) {
            return CheckResult::skipped(__('Claude was not chosen'));
        }

        $claude = $context->toolchain->which('claude');

        if ($claude === null) {
            return CheckResult::skipped(__('Claude Code is not installed'));
        }

        if ($this->enabledInSettings($context) && $this->installed($context, $claude)) {
            return CheckResult::pass(__('The superpowers plugin is installed'));
        }

        return CheckResult::warn(__('The superpowers plugin is not installed for this project'), 'claude plugin install '.ClaudeStep::PLUGIN.' --scope project');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }

    private function enabledInSettings(DoctorContext $context): bool
    {
        $path = $context->path('.claude/settings.json');
        $settings = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($settings) && ($settings['enabledPlugins'][ClaudeStep::PLUGIN] ?? null) === true;
    }

    /** Whether `claude plugin list --json` lists the plugin; its output is parsed, never its exit code trusted. */
    private function installed(DoctorContext $context, string $claude): bool
    {
        try {
            $plugins = json_decode($context->process(30)->run([$claude, 'plugin', 'list', '--json'])->output(), true);
        } catch (ProcessTimedOutException) {
            return false;
        }

        return is_array($plugins) && array_any($plugins, fn (mixed $plugin): bool => is_array($plugin) && ($plugin['id'] ?? null) === ClaudeStep::PLUGIN);
    }
}
