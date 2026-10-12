<?php

declare(strict_types=1);

/** covers §7.4 */
it('never rotates an existing APP_KEY during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $bareKeyGenerate = array_filter(
        $composer['scripts']['setup'],
        fn (string $step): bool => str_starts_with($step, '@php artisan key:generate'),
    );

    expect($bareKeyGenerate)->toBeEmpty()
        ->and(implode("\n", $composer['scripts']['setup']))->toContain('APP_KEY=');
});

it('generates the Boost guidelines during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['setup'])->toContain('@php artisan boost:update --ansi');
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
