<?php

declare(strict_types=1);

/** covers §8, §12 */

use App\Setup\SetupToken;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\KitRepository;

/**
 * A workflow file under .github/workflows, parsed as GitHub reads it.
 *
 * @return array<string, mixed>
 */
$workflow = function (string $file): array {
    $parsed = Yaml::parseFile(base_path('.github/workflows/'.$file));

    expect($parsed)->toBeArray();

    /** @var array<string, mixed> $parsed */
    return $parsed;
};

/**
 * The one step named $name of $job in $file.
 *
 * @return array<string, mixed>
 */
$step = function (string $file, string $job, string $name) use ($workflow): array {
    $steps = $workflow($file)['jobs'][$job]['steps'] ?? [];
    $found = array_values(array_filter(is_array($steps) ? $steps : [], fn (mixed $step): bool => is_array($step) && ($step['name'] ?? null) === $name));

    expect($found)->toHaveCount(1, "{$file} {$job} has no single step named [{$name}]");

    return $found[0];
};

/** The kit's package name, read from composer.json: NameLeakTest scans tests/, so this file holds no kit name literal. */
$package = fn (): string => (string) json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['name'];

/** $script run as GitHub runs a `shell: bash` step, in $path, with a fake `git` first on the PATH. */
$bash = fn (string $script, string $path, array $env = []): ProcessResult => Process::path($path)
    ->env([...$env, 'PATH' => $path.'/bin:'.getenv('PATH')])
    ->run(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $script]);

/** A fresh directory under storage/framework/testing for one run of a step, with an empty bin/ for its fakes. */
$sandbox = function (string $prefix): string {
    $directory = storage_path('framework/testing/'.$prefix.'-'.Str::uuid());
    File::ensureDirectoryExists($directory.'/bin');

    return $directory;
};

/**
 * Writes $lines as the executable bash script $path.
 *
 * @param  list<string>  $lines
 */
$executable = function (string $path, array $lines): void {
    File::put($path, implode("\n", ['#!/usr/bin/env bash', ...$lines, '']));
    chmod($path, 0755);
};

/** main's HEAD on origin, as `git ls-remote origin refs/heads/main` prints it. */
$mainLine = "0123456789abcdef0123456789abcdef01234567\trefs/heads/main\n";

/**
 * A fake git in $directory/bin that prints $answer for the one call the steps make, `git -C $directory ls-remote origin
 * refs/heads/main`, and nothing (exit 1) for anything else: another remote or branch, or `rev-parse HEAD` (the commit
 * under test).
 */
$fakeGit = function (string $directory, string $answer) use ($executable): void {
    $executable($directory.'/bin/git', [
        'if [ "$#" -eq 5 ] && [ "$1" = -C ] && [ "$2" = '.escapeshellarg($directory).' ] && [ "$3" = ls-remote ] && [ "$4" = origin ] && [ "$5" = refs/heads/main ]; then',
        '  printf %s '.escapeshellarg($answer),
        '  exit 0',
        'fi',
        'exit 1',
    ]);
};

$skipOutsideKit = fn (): bool => ! KitRepository::isKit();
$outsideKit = 'An app generated from the kit has no release check: the workflow is export-ignored.';

it('fails the kit\'s CI when the release check goes missing (M8b)', function (bool $present) use ($step, $package, $bash, $sandbox): void {
    $guard = $step('tests.yml', 'check', 'Kit keeps its release check');
    $directory = $sandbox('kit-guard');

    if ($present) {
        File::ensureDirectoryExists(dirname($directory.'/'.KitRepository::MARKER));
        File::put($directory.'/'.KitRepository::MARKER, "name: release-check\n");
    }

    try {
        $result = $bash((string) $guard['run'], $directory);

        // A guard that may fail (continue-on-error, `|| true`) lets the kit-only tests skip in the kit unnoticed.
        expect($guard['if'] ?? null)->toBe("github.repository == '{$package()}'")
            ->and($guard)->not->toHaveKey('continue-on-error')
            ->and($result->successful())->toBe($present, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'with the release check' => [true],
    'without it' => [false],
]);

// CD-2: the marker is the release check itself, and the plans leave a Packagist install with it (CD-3), so in the kit
// both are there and the kit-only tests run, and in a generated app neither is. Never skipped.
it('knows the kit by its release check, which the milestone plans go with (CD-2, CD-3)', function (): void {
    expect(KitRepository::isKit())->toBe(is_file(base_path('.github/workflows/release-check.yml')))
        ->and(is_dir(base_path('docs/superpowers/plans')))->toBe(KitRepository::isKit());
});

it('runs the release check in the kit\'s own repository only, after a release or on demand (CD-22)', function () use ($workflow, $package): void {
    $release = $workflow('release-check.yml');
    $text = (string) file_get_contents(base_path(KitRepository::MARKER));
    preg_match_all('/^\s*(?:- )?uses: (.+)$/m', $text, $uses);

    expect($release['on'] ?? null)->toBe([
        'release' => ['types' => ['published']],
        'push' => ['tags' => ['v*']],
        'workflow_dispatch' => null,
    ])
        ->and($release['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and(array_keys($release['jobs'] ?? []))->toBe(['release-check'])
        ->and($release['jobs']['release-check']['if'] ?? null)->toBe("github.repository == '{$package()}'")
        ->and($release['concurrency']['cancel-in-progress'] ?? null)->toBeTrue()
        ->and($uses[1])->not->toBeEmpty()
        ->each->toMatch('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40} # v?\d+(\.\d+)*$/');

    // The same rule as tests.yml's (CiWorkflowTest): a job never widens the token, is bounded, and checks out once.
    foreach ($release['jobs'] as $id => $job) {
        $checkouts = array_values(array_filter($job['steps'], fn (array $jobStep): bool => str_starts_with((string) ($jobStep['uses'] ?? ''), 'actions/checkout@')));

        expect($job['timeout-minutes'] ?? null)->toBeInt("{$id} has no timeout-minutes")->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
            ->and($job)->not->toHaveKey('permissions')
            ->and($checkouts)->toHaveCount(1)
            ->and($checkouts[0]['with']['persist-credentials'] ?? null)->toBeFalse("the checkout in {$id} keeps the token in .git/config")
            // The job reads HEAD's composer files and ls-remote only: the full history is never needed.
            ->and($checkouts[0]['with'] ?? [])->not->toHaveKey('fetch-depth');
    }
})->skip($skipOutsideKit, $outsideKit);

it('repeats the kit\'s Flux Pro guard on the released commit, after Setup PHP (D115)', function () use ($workflow, $step): void {
    $names = array_column($workflow('release-check.yml')['jobs']['release-check']['steps'], 'name');
    // Where a step sits; a missing one sorts last. The guard pipes into php -r, so it runs after Setup PHP.
    $position = fn (string $name): int => is_int($index = array_search($name, $names, true)) ? $index : PHP_INT_MAX;

    expect($step('release-check.yml', 'release-check', 'Kit commits no Flux Pro')['run'] ?? null)
        ->toBe($step('tests.yml', 'check', 'Kit commits no Flux Pro')['run'] ?? false)
        ->and($position('Kit commits no Flux Pro'))->toBeGreaterThan($position('Setup PHP'));
})->skip($skipOutsideKit, $outsideKit);

it('installs on the lowest PHP version composer.json allows, from the matrix (§8)', function () use ($workflow, $step): void {
    $require = (string) (json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['require']['php'] ?? '');

    expect(preg_match('/^\^(\d+\.\d+)$/', $require, $lowest))->toBe(1, "require.php [{$require}] is not a caret range this test reads")
        ->and($workflow('release-check.yml')['jobs']['release-check']['strategy']['matrix']['php'] ?? null)->toBe([$lowest[1] ?? null])
        ->and($step('release-check.yml', 'release-check', 'Setup PHP')['with']['php-version'] ?? null)->toBe('${{ matrix.php }}');
})->skip($skipOutsideKit, $outsideKit);

it('installs the published package as a user does, never the commit under test (§8)', function () use ($step, $package): void {
    $install = (string) ($step('release-check.yml', 'release-check', 'laravel new from Packagist')['run'] ?? '');

    // -v keeps Composer's Installing line in the log (the installer hides it in a pipe at normal verbosity).
    expect($install)->toContain("laravel new app --using={$package()} --no-interaction")
        ->toContain(' -v ')
        ->not->toContain(':dev-')
        ->not->toContain('repositories.')
        // Nowhere in the workflow: a Composer repository (the checkout, a path) would install the kit from elsewhere.
        ->and((string) file_get_contents(base_path('.github/workflows/release-check.yml')))
        ->not->toContain('repositories')
        ->not->toMatch('/composer\s+(?:global\s+)?config\b[^\n]*\brepo/');
})->skip($skipOutsideKit, $outsideKit);

it('waits until Packagist serves main from the kit\'s own repository, then installs (§8)', function (?array $versions, bool $passes, int $requests) use ($workflow, $step, $bash, $package, $sandbox, $executable, $fakeGit, $mainLine): void {
    $wait = $step('release-check.yml', 'release-check', 'Packagist serves main');
    $names = array_column($workflow('release-check.yml')['jobs']['release-check']['steps'], 'name');
    $directory = $sandbox('packagist');
    $fakeGit($directory, $mainLine);

    if ($versions !== null) {
        // Packagist's p2 format: the package's versions, minified (each lists only what changed from the one before).
        File::put($directory.'/packagist.json', str_replace('{kit}', $package(), (string) json_encode(['minified' => 'composer/2.0', 'packages' => ['{kit}' => $versions]], JSON_UNESCAPED_SLASHES)));
    }

    // A fake curl that serves packagist.json (missing: unreachable) for the one URL the step asks, and logs each request.
    $executable($directory.'/bin/curl', [
        'printf \'%s\n\' "$*" >> '.escapeshellarg($directory.'/requests.log'),
        'if [ "$#" -eq 2 ] && [ "$1" = -fsS ] && [ "$2" = '.escapeshellarg("https://repo.packagist.org/p2/{$package()}~dev.json").' ]; then',
        '  exec cat '.escapeshellarg($directory.'/packagist.json'),
        'fi',
        'exit 22',
    ]);

    try {
        $result = $bash((string) $wait['run'], $directory, ['GITHUB_WORKSPACE' => $directory, 'PACKAGIST_ATTEMPTS' => '2', 'PACKAGIST_INTERVAL' => '0', ...$wait['env']]);
        $output = $result->output().$result->errorOutput();

        expect($wait['env'])->toBe(['PACKAGE' => $package()])
            // By default the step waits up to 10 × 30 s.
            ->and($wait['run'])->toContain('${PACKAGIST_ATTEMPTS:-10}')->toContain('${PACKAGIST_INTERVAL:-30}')
            ->and(array_search('Packagist serves main', $names, true))->toBe(array_search('laravel new from Packagist', $names, true) - 1)
            ->and($result->successful())->toBe($passes, $output)
            ->and(count(file($directory.'/requests.log') ?: []))->toBe($requests);

        if (! $passes) {
            // The failure names both what Packagist serves and what it should: main's commit from the kit's repository.
            expect($output)->toContain('FAIL')
                ->toContain('0123456789abcdef0123456789abcdef01234567')
                ->toContain("https://github.com/{$package()}.git");
        }
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'main served' => [[['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://github.com/{kit}.git', 'reference' => '0123456789abcdef0123456789abcdef01234567']]], true, 1],
    'main served after another branch' => [[
        ['version' => 'dev-develop', 'source' => ['type' => 'git', 'url' => 'https://github.com/{kit}.git', 'reference' => '89abcdef0123456789abcdef0123456789abcdef']],
        ['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://github.com/{kit}.git', 'reference' => '0123456789abcdef0123456789abcdef01234567']],
    ], true, 1],
    'an older main, after every attempt' => [[['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://github.com/{kit}.git', 'reference' => '89abcdef0123456789abcdef0123456789abcdef']]], false, 2],
    'main from another repository' => [[['version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://github.com/mirror/{kit}.git', 'reference' => '0123456789abcdef0123456789abcdef01234567']]], false, 2],
    'Packagist unreachable' => [null, false, 2],
])->skip($skipOutsideKit, $outsideKit);

it('passes only when the installed reference is main\'s HEAD (§8, criterion 4)', function (string $log, string $lsRemote, bool $passes) use ($step, $bash, $package, $sandbox, $fakeGit): void {
    $check = $step('release-check.yml', 'release-check', 'The installed reference is main');
    $directory = $sandbox('release-check');
    File::put($directory.'/install.log', str_replace('{kit}', $package(), $log));
    $fakeGit($directory, $lsRemote);

    try {
        $result = $bash((string) $check['run'], $directory, ['GITHUB_WORKSPACE' => $directory, ...$check['env']]);

        expect($check['env'])->toBe(['PACKAGE' => $package()])
            ->and($result->successful())->toBe($passes, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    // Composer's lines, as the installer's log carries them: the downloader's, and create-project's header (full sha).
    'main, as the downloader names it' => ["  - Installing {kit} (dev-main 0123456): Extracting archive\n", "0123456789abcdef0123456789abcdef01234567\trefs/heads/main\n", true],
    'main, in create-project\'s header' => ["Creating a \"{kit}\" project at \"./app\"\nInstalling {kit} (dev-main 0123456789abcdef0123456789abcdef01234567)\n", "0123456789abcdef0123456789abcdef01234567\trefs/heads/main\n", true],
    'another commit' => ["  - Installing {kit} (dev-main 89abcde): Extracting archive\n", "0123456789abcdef0123456789abcdef01234567\trefs/heads/main\n", false],
    'another package\'s dev-main at main\'s commit first' => ["  - Installing acme/other (dev-main 0123456789abcdef0123456789abcdef01234567): Extracting archive\n  - Installing {kit} (dev-main 89abcde): Extracting archive\n", "0123456789abcdef0123456789abcdef01234567\trefs/heads/main\n", false],
    'no install line' => ["Creating a project\n", "0123456789abcdef0123456789abcdef01234567\trefs/heads/main\n", false],
    'an empty ls-remote' => ["  - Installing {kit} (dev-main 0123456): Extracting archive\n", '', false],
])->skip($skipOutsideKit, $outsideKit);

it('checks that a dist install ships the docs and leaves the kit-only files out (F50, M8b)', function (string $variant, bool $passes) use ($step, $bash, $sandbox, $executable): void {
    $run = (string) ($step('release-check.yml', 'release-check', 'A dist install')['run'] ?? '');
    $spec = 'docs/superpowers/specs/'.basename((glob(base_path('docs/superpowers/specs/*-design.md')) ?: [''])[0]);
    preg_match('/for file in ([^;]+); do/', $run, $list);
    $directory = $sandbox('dist-install');

    // An app as a Packagist install lays it out: the docs, the setup token, and none of the kit-only files.
    foreach (['README.md', 'LICENSE', 'CHANGELOG.md', 'docs/handleiding.html', $spec, SetupToken::PATH] as $file) {
        File::ensureDirectoryExists(dirname($directory.'/'.$file));
        File::put($directory.'/'.$file, "x\n");
    }

    if ($variant === 'no licence') {
        File::delete($directory.'/LICENSE');
    } elseif ($variant === 'no setup token') {
        File::delete($directory.'/'.SetupToken::PATH);
    } elseif ($variant === 'plans shipped') {
        File::ensureDirectoryExists($directory.'/docs/superpowers/plans');
    } elseif ($variant === 'release check shipped') {
        File::ensureDirectoryExists($directory.'/.github/workflows');
        File::put($directory.'/.github/workflows/release-check.yml', "x\n");
    }

    // A fake php: the token's path from SetupToken::PATH, and the wizard's route for the exact route:list call.
    $executable($directory.'/bin/php', [
        'if [ "$#" -eq 2 ] && [ "$1" = -r ] && [[ "$2" == *SetupToken::PATH* ]]; then printf %s '.escapeshellarg(SetupToken::PATH).'; exit 0; fi',
        'if [ "$*" = \'artisan route:list --path=welcome-setup --no-ansi\' ]; then',
        '  printf \'%s\n\' '.escapeshellarg($variant === 'no wizard route' ? '  GET|HEAD   dashboard ....... dashboard' : '  GET|HEAD   welcome-setup ....... setup.wizard'),
        '  exit 0',
        'fi',
        'exit 1',
    ]);

    try {
        $result = $bash($run, $directory);

        expect(explode(' ', $list[1] ?? ''))->toBe(['README.md', 'LICENSE', 'CHANGELOG.md', 'docs/handleiding.html', $spec])
            // The path comes from the app's SetupToken::PATH, as scripts/e2e-local.sh reads it, never a literal.
            ->and($run)->toContain('SetupToken::PATH')->not->toContain(SetupToken::PATH)
            ->and($result->successful())->toBe($passes, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'a dist install' => ['complete', true],
    'no licence' => ['no licence', false],
    'the plans shipped' => ['plans shipped', false],
    'the release check shipped' => ['release check shipped', false],
    'no setup token' => ['no setup token', false],
    'no wizard route' => ['no wizard route', false],
])->skip($skipOutsideKit, $outsideKit);
