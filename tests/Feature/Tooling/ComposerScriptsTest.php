<?php

declare(strict_types=1);

/** covers §7.4 */
it('never rotates an existing APP_KEY during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    // skv:bootstrap keeps an existing key (BootstrapTest); setup never calls key:generate itself.
    expect($composer['scripts']['setup'])->not->toContain('@php artisan key:generate --ansi')
        ->toContain('@php artisan skv:bootstrap --no-interaction');
});

it('generates the Boost guidelines during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    // The Claude step runs boost:install (ClaudeStepTest).
    expect($composer['scripts']['setup'])->toContain('@php artisan skv:install');
});

it('checks Playwright and the Vite build before running the browser suite (§7.4)', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $script = implode("\n", $composer['scripts']['test:browser']);

    expect($script)->toContain('node_modules/.bin/playwright')
        ->toContain('public/build/manifest.json')
        ->toContain('pest --testsuite=Browser');
});

it('keeps the browser suite out of the default test run (§7.2)', function (): void {
    expect((string) file_get_contents(base_path('phpunit.xml')))->toContain('defaultTestSuite="Unit,Feature,Arch"');
});

it('runs skv:bootstrap from the Composer and installer hooks (§5.2, RL-13)', function (): void {
    /** @var array{scripts: array<string, list<string>>, extra: array{laravel: array{installer: array{post-create-project: list<string>}}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['post-root-package-install'])->toBe(["@php -r \"file_exists('.env') || copy('.env.example', '.env');\""])
        ->and($composer['scripts']['post-create-project-cmd'])->toBe(['@php artisan skv:bootstrap --no-interaction --ansi'])
        ->and($composer['extra']['laravel']['installer']['post-create-project'])->toBe(['@php artisan skv:bootstrap --no-interaction']);
});

it('sets up through skv:bootstrap and skv:install (§5.3.3)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['setup'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'composer install',
        "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
        '@php artisan skv:bootstrap --no-interaction',
        '@php artisan skv:install',
    ]);
});
