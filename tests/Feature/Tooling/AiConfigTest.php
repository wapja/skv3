<?php

declare(strict_types=1);

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
