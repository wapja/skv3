<?php

declare(strict_types=1);

/** covers §9 */

use Laravel\Boost\Install\Skill;
use Laravel\Boost\Install\SkillComposer;

it('imports the Boost guidelines from CLAUDE.md', function (): void {
    expect(file_get_contents(base_path('CLAUDE.md')))->toContain('@AGENTS.md');
});

it('ships Claude settings with superpowers and safety rules', function (): void {
    /** @var array{permissions: array{deny: list<string>, allow: list<string>}, enabledPlugins: array<string, bool>, extraKnownMarketplaces: array<string, mixed>} $settings */
    $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($settings['enabledPlugins'])->toHaveKey('superpowers@superpowers-marketplace')
        ->and($settings['extraKnownMarketplaces'])->toHaveKey('superpowers-marketplace')
        ->and($settings['permissions']['deny'])->toContain('Bash(herd sites:*)')
        ->and($settings['permissions']['deny'])->toContain('Bash(php artisan migrate:fresh:*)')
        ->and($settings['permissions']['deny'])->toContain('Bash(php artisan db:wipe:*)')
        ->and($settings['permissions']['deny'])->toContain('Bash(php artisan migrate:reset:*)')
        ->and($settings['permissions']['deny'])->toContain('Bash(php artisan migrate:refresh:*)')
        ->and($settings['permissions']['deny'])->toContain('Bash(php artisan migrate:rollback:*)')
        ->and($settings['permissions']['deny'])->toContain('mcp__herd__get_all_sites')
        ->and($settings['permissions']['deny'])->toContain('mcp__herd__get_site_information')
        ->and($settings['permissions']['deny'])->toContain('mcp__herd__get_forge_environment_variables')
        ->and($settings['permissions']['deny'])->toContain('mcp__herd__get_last_deployment_information')
        ->and($settings['permissions']['deny'])->toContain('Read(./.env)')
        ->and($settings['permissions']['deny'])->not->toContain('Read(./.env*)')
        ->and($settings['permissions']['allow'])->not->toContain('Bash(php artisan:*)')
        ->and($settings['permissions']['allow'])->toContain('Bash(php artisan test:*)');
});

it('targets Claude Code in boost.json', function (): void {
    /** @var array{agents: list<string>} $boost */
    $boost = json_decode((string) file_get_contents(base_path('boost.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($boost['agents'])->toContain('claude_code');
});

it('runs Pint synchronously from the project dir after PHP edits', function (): void {
    /** @var array{hooks: array{PostToolUse: list<array{matcher: string, hooks: list<array<string, mixed>>}>}} $settings */
    $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, flags: JSON_THROW_ON_ERROR);

    $hook = $settings['hooks']['PostToolUse'][0]['hooks'][0];

    expect($hook)->not->toHaveKey('async')
        ->and($hook['command'])->toContain('"$CLAUDE_PROJECT_DIR"/vendor/bin/pint')
        ->and($hook['command'])->toContain('*.blade.php) ;;')
        ->and($hook['command'])->toEndWith('exit 0');
});

it('does not list allow rules that can never match colon subcommands', function (): void {
    /** @var array{permissions: array{allow: list<string>}} $settings */
    $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($settings['permissions']['allow'])->not->toContain('Bash(php artisan make:*)')
        ->and($settings['permissions']['allow'])->not->toContain('Bash(php artisan livewire:*)');
});

it('publishes the four kit skills of §9 through Boost (CD-5)', function (): void {
    $skills = app(SkillComposer::class)->skills();

    foreach (['add-tenant-resource', 'add-permission', 'add-settings-page', 'write-tenant-test'] as $name) {
        $skill = $skills->get($name);

        expect($skill)->toBeInstanceOf(Skill::class, "Boost does not discover the {$name} skill")
            ->and($skill?->path)->toBe(base_path('.ai/skills/'.$name))
            ->and($skill?->custom)->toBeTrue()
            ->and(mb_strlen((string) $skill?->description))->toBeGreaterThan(80);
    }
});

it('names only files, classes and methods that exist in the kit skills (CD-5)', function (): void {
    $missing = [];

    foreach (glob(base_path('.ai/skills/*/SKILL.md')) ?: [] as $file) {
        preg_match_all('/`([^`\s]+)`/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $token) {
            $exists = match (true) {
                preg_match('#^(?:app|config|database|lang|resources|routes|scripts|tests)/[\w./-]+$#', $token) === 1 => file_exists(base_path($token)),
                preg_match('/^(App\\\\[\w\\\\]+)(?:::(\w+)(?:\(\))?)?$/', $token, $parts) === 1 => match (true) {
                    ! class_exists($parts[1]) && ! trait_exists($parts[1]) && ! enum_exists($parts[1]) && ! interface_exists($parts[1]) => false,
                    ! isset($parts[2]) => true,
                    default => method_exists($parts[1], $parts[2]) || defined($parts[1].'::'.$parts[2]),
                },
                default => true,
            };

            if (! $exists) {
                $missing[] = basename(dirname($file)).': '.$token;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('allows the doctor and the setup stylesheet build and enables the Boost and Herd MCP servers (§9)', function (): void {
    /** @var array{permissions: array{allow: list<string>}, enabledMcpjsonServers: list<string>} $settings */
    $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($settings['permissions']['allow'])->toContain('Bash(composer doctor)', 'Bash(npm run build:setup)')
        ->and($settings['enabledMcpjsonServers'])->toBe(['laravel-boost', 'herd']);
});

it('denies the Herd tools that read secrets or captured requests and asks before one changes a site or a service (§9)', function (): void {
    /** @var array{permissions: array{deny: list<string>, ask?: list<string>, allow: list<string>}} $settings */
    $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, flags: JSON_THROW_ON_ERROR);
    $herd = fn (array $rules): array => array_values(array_filter($rules, fn (string $rule): bool => str_starts_with($rule, 'mcp__herd__')));

    // enabledMcpjsonServers approves the server, so a tool neither denied nor asked would run in an accept or auto mode.
    expect($herd($settings['permissions']['deny']))->toBe([
        'mcp__herd__get_all_sites',
        'mcp__herd__get_site_information',
        'mcp__herd__get_forge_environment_variables',
        'mcp__herd__get_last_deployment_information',
        'mcp__herd__find_available_services',
        'mcp__herd__start_debug_session',
        'mcp__herd__stop_debug_session',
    ])->and($herd($settings['permissions']['ask'] ?? []))->toBe([
        'mcp__herd__install_service',
        'mcp__herd__start_or_stop_service',
        'mcp__herd__install_php_version',
        'mcp__herd__secure_or_unsecure_site',
        'mcp__herd__isolate_or_unisolate_site',
    ])->and($herd($settings['permissions']['allow']))->toBe([]);
});
