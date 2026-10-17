<?php

declare(strict_types=1);

/** covers §7.4 */

use Illuminate\Support\Str;

it('never rotates an existing APP_KEY during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    // skv:bootstrap keeps an existing key (BootstrapTest); setup never calls key:generate itself.
    expect($composer['scripts']['setup'])->not->toContain('@php artisan key:generate --ansi')
        ->toContain('@php artisan skv:bootstrap --no-interaction');
});

it('leaves the Boost guidelines to the Claude step of skv:install, never to boost:update (CD-11)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    // AGENTS.md comes from the Claude step's boost:install (ClaudeStepTest); composer setup runs no Boost command itself.
    expect($composer['scripts']['setup'])->toContain('@php artisan skv:install')
        ->and(implode("\n", $composer['scripts']['setup']))->not->toContain('boost:');
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

    expect($composer['scripts']['post-root-package-install'])->toBe(["@php -r \"file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));\""])
        ->and($composer['scripts']['post-create-project-cmd'])->toBe(['@php artisan skv:bootstrap --no-interaction --ansi'])
        ->and($composer['extra']['laravel']['installer']['post-create-project'])->toBe(['@php artisan skv:bootstrap --no-interaction']);
});

it('sets up through skv:bootstrap and skv:install (§5.3.3)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['setup'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'composer install',
        "@php -r \"file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));\"",
        '@php artisan skv:bootstrap --no-interaction',
        '@php artisan skv:install',
    ]);
});

it('sets the hooks path in a git worktree too (DEC-25)', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(implode("\n", $composer['scripts']['setup']))->not->toContain("is_dir('.git')");
});

it('runs the pgsql suite on the sanitised test database (A7, RL-14)', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(implode("\n", $composer['scripts']['test:pgsql']))
        ->toContain('DB_DATABASE=${DB_TEST_DATABASE:-$(php scripts/create-pgsql-test-database.php --print-name)}')
        ->not->toContain('$(basename "$PWD")_test');
});

it('ends composer setup with the CLI after the commands the clone route repeats (RL-11, §5.3.3)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $setup = $composer['scripts']['setup'];

    expect(array_slice($setup, 1, 3))->toBe([
        'composer install',
        "@php -r \"file_exists('.env') || (copy('.env.example', '.env') && chmod('.env', 0600));\"",
        '@php artisan skv:bootstrap --no-interaction',
    ])->and(end($setup))->toBe('@php artisan skv:install');
});

it('repeats the commands of composer setup in the clone route of scripts/e2e-local.sh (g4-25, RL-11)', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $normalise = fn (string $command): string => str_replace(' --no-interaction', '', (string) preg_replace('/^@php /', 'php ', $command));
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));
    $marker = "        clone)\n";

    // L7b-74: without the arm's marker Str::between() returns the whole script, which holds the commands elsewhere.
    expect($script)->toContain($marker);

    $clone = $normalise(Str::betweenFirst($script, $marker, ';;'));
    $offset = 0;

    foreach (array_slice($composer['scripts']['setup'], 1, 3) as $command) {
        $position = strpos($clone, $normalise($command), $offset);

        expect($position)->not->toBeFalse("The clone route lacks [{$command}] after the commands before it.");

        $offset = (int) $position + 1;
    }
});
