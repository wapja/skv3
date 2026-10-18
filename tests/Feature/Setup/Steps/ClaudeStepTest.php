<?php

declare(strict_types=1);

/** covers §5.3.3, §9 (DEC-25) */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\ClaudeStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    // Stubs for herd, Herd's MCP phar and claude: the step must not look at /Applications or the real HOME.
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());

    foreach (['Library/Application Support/Herd/bin/herd', 'Library/Application Support/Herd/bin/herd-mcp.phar', '.local/bin/claude'] as $binary) {
        File::ensureDirectoryExists(dirname($this->home.'/'.$binary));
        File::put($this->home.'/'.$binary, "#!/bin/sh\n");
        chmod($this->home.'/'.$binary, 0755);
    }

    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
    app()->instance(Toolchain::class, new Toolchain($this->home));

    // The kit's composer.json before the step: in a generated app the Claude step of skv:install already added boost:update.
    /** @var array{scripts: array{post-update-cmd?: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $composer['scripts']['post-update-cmd'] = array_values(array_filter($composer['scripts']['post-update-cmd'] ?? [], fn (string $line): bool => ! str_contains($line, 'boost:update')));
    $this->composerJson = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
});

it('is skipped when Claude was not chosen', function (): void {
    expect(new ClaudeStep()->run(setupSandbox())->status)->toBe('skipped');
});

it('installs Boost, registers the Herd MCP server as herd and adds boost:update once (§5.3.3 step 7, DEC-25)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['claude' => true], $detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://my-app.test']);
    File::put($context->path('composer.json'), $this->composerJson);
    File::put($context->path('.mcp.json'), json_encode(['mcpServers' => ['laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]], JSON_THROW_ON_ERROR));

    Process::fake([
        "*'artisan' 'boost:install'*" => Process::result('Boost installed'),
        "*claude' 'plugin'*" => Process::result('installed'),
    ]);

    expect(new ClaudeStep()->run($context)->status)->toBe('done');

    /** @var array{mcpServers: array<string, array{command: string, args: list<string>, env?: array<string, string>}>} $mcp */
    $mcp = json_decode((string) file_get_contents($context->path('.mcp.json')), true);
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents($context->path('composer.json')), true);

    expect($mcp['mcpServers'])->toHaveKeys(['laravel-boost', 'herd'])
        // Without SITE_PATH, Herd's MCP server offers no tool or resource with this site's .env (§9).
        ->and($mcp['mcpServers']['herd'])->not->toHaveKey('env')
        ->and($mcp['mcpServers']['herd']['args'][0] ?? null)->toBe($this->home.'/Library/Application Support/Herd/bin/herd-mcp.phar')
        ->and(array_filter($composer['scripts']['post-update-cmd'], fn (string $line): bool => str_contains($line, 'boost:update')))->toHaveCount(1)
        ->and($context->path('.claude/settings.local.json'))->toBeFile();
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'plugin install superpowers@superpowers-marketplace --scope project')
        && $process->path === $context->basePath);
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'composer require'));

    new ClaudeStep()->run($context);
    $composer = json_decode((string) file_get_contents($context->path('composer.json')), true);

    expect(array_filter($composer['scripts']['post-update-cmd'], fn (string $line): bool => str_contains($line, 'boost:update')))->toHaveCount(1);
});

it('hands over the plugin command when the plugin install fails, after Boost and the Herd MCP server (§5.3.3 step 7)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['claude' => true], $detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://my-app.test']);

    Process::fake([
        "*'artisan' 'boost:install'*" => Process::result('Boost installed'),
        "*claude' 'plugin'*" => Process::result(errorOutput: "Plugin not found\n", exitCode: 1),
    ]);

    $result = new ClaudeStep()->run($context);

    /** @var array{mcpServers: array<string, mixed>} $mcp */
    $mcp = json_decode((string) file_get_contents($context->path('.mcp.json')), true);

    expect($result->isManual())->toBeTrue()
        ->and($result->command)->toBe('claude plugin install superpowers@superpowers-marketplace --scope project')
        ->and($result->message)->toBe('Plugin not found')
        ->and($mcp['mcpServers'])->toHaveKey('herd');
});

it('is complete once Boost, the MCP servers and boost:update are in place (§5.3.1)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['claude' => true], $detection, $context->folderName());
    File::put($context->path('composer.json'), $this->composerJson);
    File::put($context->path('AGENTS.md'), '');
    File::put($context->path('.mcp.json'), json_encode(['mcpServers' => ['laravel-boost' => ['command' => 'php']]], JSON_THROW_ON_ERROR));

    expect(new ClaudeStep()->isComplete($context))->toBeFalse();

    File::put($context->path('.mcp.json'), json_encode(['mcpServers' => ['laravel-boost' => ['command' => 'php'], 'herd' => ['command' => 'php']]], JSON_THROW_ON_ERROR));

    expect(new ClaudeStep()->isComplete($context))->toBeFalse();

    /** @var array{scripts: array{post-update-cmd: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents($context->path('composer.json')), true);
    $composer['scripts']['post-update-cmd'][] = '@php artisan boost:update --ansi';
    File::put($context->path('composer.json'), json_encode($composer, JSON_THROW_ON_ERROR));

    expect(new ClaudeStep()->isComplete($context))->toBeTrue();
});

it('hands over the Boost command when the address is not a .test site (§5.3.3 step 7)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['claude' => true], $context->detection, $context->folderName());

    $result = new ClaudeStep()->run($context);
    Process::assertNothingRan();

    expect($result->isManual())->toBeTrue()->and($result->command)->toBe('php artisan boost:install');
});

it('restores the Boost constraint the installer lowered, also when Claude was not chosen (DEC-22 rule 5, §5.2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['claude' => false], $context->detection, $context->folderName());
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $composer['require-dev']['laravel/boost'] = '^2.2';
    File::put($context->path('composer.json'), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    expect(new ClaudeStep()->isComplete($context))->toBeFalse();

    Process::fake(["*composer' 'require' '--dev' 'laravel/boost:^2.10'*" => Process::result('')]);

    expect(new ClaudeStep()->run($context)->status)->toBe('skipped');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require --dev laravel/boost:^2.10'));
});

it('replaces symlinked project files instead of writing through them (RL-1, g2-07)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['claude' => true], $detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://my-app.test']);
    $outside = $this->home.'/outside';
    File::ensureDirectoryExists($outside);
    File::put($outside.'/composer.json', $this->composerJson);
    File::put($outside.'/mcp.json', '{"mcpServers":{}}');
    File::ensureDirectoryExists($context->path('.claude'));
    symlink($outside.'/composer.json', $context->path('composer.json'));
    symlink($outside.'/mcp.json', $context->path('.mcp.json'));
    // Dangling: the step creates settings.local.json when it is missing.
    symlink($outside.'/settings.local.json', $context->path('.claude/settings.local.json'));
    $composer = (string) file_get_contents($outside.'/composer.json');

    Process::fake([
        "*'artisan' 'boost:install'*" => Process::result('Boost installed'),
        "*claude' 'plugin'*" => Process::result('installed'),
    ]);

    expect(new ClaudeStep()->run($context)->status)->toBe('done')
        ->and(file_get_contents($outside.'/composer.json'))->toBe($composer)
        ->and(file_get_contents($outside.'/mcp.json'))->toBe('{"mcpServers":{}}')
        ->and($outside.'/settings.local.json')->not->toBeFile()
        ->and(array_filter(['composer.json', '.mcp.json', '.claude/settings.local.json'], fn (string $file): bool => is_link($context->path($file))))->toBe([])
        ->and((string) file_get_contents($context->path('composer.json')))->toContain('boost:update');
});
