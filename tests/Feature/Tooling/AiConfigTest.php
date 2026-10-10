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
        ->and($settings['permissions']['deny'])->toContain('Read(./.env*)');
});

it('targets Claude Code in boost.json', function (): void {
    /** @var array{agents: list<string>} $boost */
    $boost = json_decode((string) file_get_contents(base_path('boost.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($boost['agents'])->toContain('claude_code');
});
