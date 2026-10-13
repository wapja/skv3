<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/**
 * §5.4, DEC-25 (6): when Claude was chosen, `.mcp.json` registers laravel-boost and, under Herd, the Herd MCP server
 * under the name `herd`, so the `mcp__herd__get_all_sites` deny applies.
 */
final readonly class McpCheck implements Check
{
    public function key(): string
    {
        return 'mcp';
    }

    public function label(): string
    {
        return __('MCP servers');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->answer('claude') !== true) {
            return CheckResult::skipped(__('Claude was not chosen'));
        }

        $wanted = $context->detection->herd ? ['laravel-boost', 'herd'] : ['laravel-boost'];
        $missing = array_values(array_diff($wanted, $this->servers($context)));

        if ($missing === []) {
            return CheckResult::pass(__('.mcp.json registers :servers', ['servers' => implode(', ', $wanted)]));
        }

        return CheckResult::fail(__('.mcp.json does not register :servers', ['servers' => implode(', ', $missing)]), 'php artisan skv:install --force');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }

    /** @return list<string> the server names in .mcp.json's mcpServers */
    private function servers(DoctorContext $context): array
    {
        $path = $context->path('.mcp.json');
        $config = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $servers = is_array($config) ? ($config['mcpServers'] ?? null) : null;

        return is_array($servers) ? array_map(strval(...), array_keys($servers)) : [];
    }
}
