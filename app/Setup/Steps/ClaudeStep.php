<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use stdClass;

/**
 * §5.3.3 step 7 (long, when chosen): `boost:install` on a .test address, the Herd MCP server in .mcp.json under the
 * name `herd` (so the `mcp__herd__get_all_sites` deny applies, DEC-25 (6)), the superpowers plugin when `claude` is
 * on PATH, a local `.claude/settings.local.json` (§9) and one `boost:update` in `post-update-cmd`. Whatever the
 * answers, it first undoes the installer's `--boost` edits: a lowered `laravel/boost` constraint (DEC-22, §5.2).
 */
final readonly class ClaudeStep implements SetupStep
{
    private const string BOOST_CONSTRAINT = '^2.10';

    private const string BOOST_UPDATE = '@php artisan boost:update --ansi';

    private const string PLUGIN = 'superpowers@superpowers-marketplace';

    private const string HERD_APP_PHAR = '/Applications/Herd.app/Contents/Resources/herd-mcp.phar';

    private Toolchain $toolchain;

    private bool $toolchainInjected;

    public function __construct(?Toolchain $toolchain = null)
    {
        $this->toolchainInjected = $toolchain instanceof Toolchain;
        $this->toolchain = $toolchain ?? app(Toolchain::class);
    }

    public function key(): string
    {
        return 'claude';
    }

    public function label(): string
    {
        return __('Claude Code');
    }

    public function isComplete(SetupContext $context): bool
    {
        $composer = $this->composer($context);

        if ($composer !== null && ($this->boostIsLowered($composer) || ($this->listsBoost($composer) && $this->boostUpdateLines($composer) !== 1))) {
            return false;
        }

        if (! $context->answers->claude) {
            return true;
        }

        $servers = $this->mcpServers($context);

        return is_file($context->path('AGENTS.md'))
            && property_exists($servers, 'laravel-boost')
            && (! $this->registersHerd($context) || property_exists($servers, 'herd'))
            && $composer !== null && $this->boostUpdateLines($composer) === 1;
    }

    public function isLong(): bool
    {
        return true;
    }

    public function run(SetupContext $context): StepResult
    {
        $composer = $this->composer($context);

        if ($composer !== null && $this->boostIsLowered($composer)) {
            $require = $this->process($context, 600)->run([$this->toolchain->which('composer') ?? 'composer', 'require', '--dev', 'laravel/boost:'.self::BOOST_CONSTRAINT, '--no-interaction']);

            if ($require->failed()) {
                return StepResult::manual('composer require --dev "laravel/boost:'.self::BOOST_CONSTRAINT.'"', $this->lastLine($require));
            }
        }

        $this->addBoostUpdate($context);

        if (! $context->answers->claude) {
            return StepResult::skipped(__('Not chosen'));
        }

        $host = parse_url((string) new EnvFile($context->path('.env'))->get('APP_URL'), PHP_URL_HOST);

        if (! is_string($host) || ! str_ends_with($host, '.test')) {
            return StepResult::manual('php artisan boost:install', __('Boost needs a .test address; run it after the Herd step.'));
        }

        $boost = $this->process($context, 300)->run([PHP_BINARY, 'artisan', 'boost:install', '--no-interaction', '--guidelines', '--skills', '--mcp']);

        if ($boost->failed()) {
            return StepResult::manual('php artisan boost:install', $this->lastLine($boost));
        }

        $this->registerHerdMcp($context);
        $this->addBoostUpdate($context);

        if (! is_file($context->path('.claude/settings.local.json'))) {
            if (! is_dir($context->path('.claude'))) {
                mkdir($context->path('.claude'), 0755, true);
            }

            file_put_contents($context->path('.claude/settings.local.json'), "{}\n");
        }

        $claude = $this->toolchain->which('claude');

        if ($claude !== null) {
            $plugin = $this->process($context, 300)->run([$claude, 'plugin', 'install', self::PLUGIN, '--scope', 'project']);

            if ($plugin->failed()) {
                return StepResult::manual('claude plugin install '.self::PLUGIN.' --scope project', $this->lastLine($plugin));
            }
        }

        return StepResult::done();
    }

    /**
     * Herd's MCP server: next to the herd binary the toolchain found, else inside Herd.app, the latter only when no
     * toolchain was injected (so tests never read /Applications); null when neither exists.
     */
    public function herdMcpPhar(): ?string
    {
        $phar = dirname((string) $this->toolchain->herd()).'/herd-mcp.phar';

        if ($this->toolchain->herd() !== null && is_file($phar)) {
            return $phar;
        }

        return ! $this->toolchainInjected && is_file(self::HERD_APP_PHAR) ? self::HERD_APP_PHAR : null;
    }

    private function registersHerd(SetupContext $context): bool
    {
        return $context->detection->herd && $this->herdMcpPhar() !== null;
    }

    /** Adds the Herd MCP server as `herd` to .mcp.json (created when missing), keeping every other server. */
    private function registerHerdMcp(SetupContext $context): void
    {
        $phar = $this->herdMcpPhar();

        if (! $context->detection->herd || $phar === null) {
            return;
        }

        $path = $context->path('.mcp.json');
        $config = is_file($path) ? json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR) : null;
        $config = $config instanceof stdClass ? $config : new stdClass;
        $servers = $this->mcpServers($context);

        $servers->herd = ['command' => 'php', 'args' => [$phar], 'env' => ['SITE_PATH' => $context->basePath]];
        $config->mcpServers = $servers;

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** The mcpServers object of .mcp.json, or an empty one. */
    private function mcpServers(SetupContext $context): stdClass
    {
        $path = $context->path('.mcp.json');
        $config = is_file($path) ? json_decode((string) file_get_contents($path), false) : null;

        return $config instanceof stdClass && isset($config->mcpServers) && $config->mcpServers instanceof stdClass
            ? $config->mcpServers
            : new stdClass;
    }

    /** Appends one `boost:update` to `post-update-cmd` when composer.json lists laravel/boost and has none yet. */
    private function addBoostUpdate(SetupContext $context): void
    {
        $composer = $this->composer($context);

        if ($composer === null || ! $this->listsBoost($composer) || $this->boostUpdateLines($composer) > 0) {
            return;
        }

        $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        $postUpdate = is_array($scripts['post-update-cmd'] ?? null) ? array_values($scripts['post-update-cmd']) : (isset($scripts['post-update-cmd']) ? [$scripts['post-update-cmd']] : []);
        $postUpdate[] = self::BOOST_UPDATE;
        $scripts['post-update-cmd'] = $postUpdate;
        $composer['scripts'] = $scripts;

        file_put_contents($context->path('composer.json'), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /** @param  array<mixed>  $composer */
    private function boostUpdateLines(array $composer): int
    {
        $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        $postUpdate = $scripts['post-update-cmd'] ?? [];

        return count(array_filter(
            is_array($postUpdate) ? $postUpdate : [$postUpdate],
            fn (mixed $line): bool => is_string($line) && str_contains($line, 'boost:update'),
        ));
    }

    /** @param  array<mixed>  $composer */
    private function listsBoost(array $composer): bool
    {
        return isset($composer['require']['laravel/boost']) || isset($composer['require-dev']['laravel/boost']);
    }

    /**
     * Whether require-dev holds a laravel/boost constraint whose version is below ^2.10 (the installer's ^2.2).
     *
     * @param  array<mixed>  $composer
     */
    private function boostIsLowered(array $composer): bool
    {
        $constraint = $composer['require-dev']['laravel/boost'] ?? null;

        if (! is_string($constraint) || $constraint === self::BOOST_CONSTRAINT || preg_match('/\d+(?:\.\d+)*/', $constraint, $matches) !== 1) {
            return false;
        }

        return version_compare($matches[0], '2.10', '<');
    }

    /** @return array<mixed>|null the decoded composer.json of the base path, or null when it is missing */
    private function composer(SetupContext $context): ?array
    {
        $path = $context->path('composer.json');

        if (! is_file($path)) {
            return null;
        }

        $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($composer) ? $composer : null;
    }

    /** The last non-empty line the command printed, or its exit code. */
    private function lastLine(ProcessResult $result): string
    {
        $lines = array_filter(array_map(trim(...), preg_split('/\R/', $result->output()."\n".$result->errorOutput()) ?: []));

        return $lines === [] ? __('The command failed (exit code :code).', ['code' => $result->exitCode()]) : end($lines);
    }

    private function process(SetupContext $context, int $timeout): PendingProcess
    {
        return Process::path($context->basePath)->env($this->toolchain->environment())->timeout($timeout);
    }
}
