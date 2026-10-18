<?php

declare(strict_types=1);

/** covers §8, §12 */

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

$skipOutsideKit = fn (): bool => ! KitRepository::isKit();
$outsideKit = 'An app generated from the kit has no release check: the workflow is export-ignored.';

it('fails the kit\'s CI when the release check goes missing (M8b)', function () use ($step, $package): void {
    $guard = $step('tests.yml', 'check', 'Kit keeps its release check');

    expect($guard['if'] ?? null)->toBe("github.repository == '{$package()}'")
        ->and($guard['run'] ?? null)->toContain('test -f '.KitRepository::MARKER);
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
        ->and($release['jobs']['release-check']['timeout-minutes'] ?? null)->toBeInt()->toBeLessThanOrEqual(60)
        ->and($uses[1])->not->toBeEmpty()
        ->each->toMatch('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40} # v?\d+(\.\d+)*$/');

    foreach ($release['jobs']['release-check']['steps'] as $jobStep) {
        if (str_starts_with((string) ($jobStep['uses'] ?? ''), 'actions/checkout@')) {
            expect($jobStep['with']['persist-credentials'] ?? null)->toBeFalse();
        }
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

it('installs the published package as a user does, never the commit under test (§8)', function () use ($step, $package): void {
    $install = (string) ($step('release-check.yml', 'release-check', 'laravel new from Packagist')['run'] ?? '');

    // -v keeps Composer's Installing line in the log (the installer hides it in a pipe at normal verbosity).
    expect($install)->toContain("laravel new app --using={$package()} --no-interaction")
        ->toContain(' -v ')
        ->not->toContain(':dev-')
        ->not->toContain('repositories.');
})->skip($skipOutsideKit, $outsideKit);

it('passes only when the installed reference is main\'s HEAD (§8, criterion 4)', function (?string $reference, bool $passes) use ($step, $bash, $package): void {
    $check = $step('release-check.yml', 'release-check', 'The installed reference is main');
    $directory = storage_path('framework/testing/release-check-'.Str::uuid());
    File::ensureDirectoryExists($directory.'/bin');
    // Composer's create-project line, as the installer's log carries it.
    File::put($directory.'/install.log', $reference === null ? "Creating a project\n" : "  - Installing {$package()} (dev-main {$reference}): Extracting archive\n");
    // A fake git that answers ls-remote the way GitHub does for main.
    File::put($directory.'/bin/git', "#!/usr/bin/env bash\nprintf '0123456789abcdef0123456789abcdef01234567\\trefs/heads/main\\n'\n");
    chmod($directory.'/bin/git', 0755);

    try {
        $result = $bash((string) $check['run'], $directory, ['GITHUB_WORKSPACE' => $directory, ...$check['env']]);

        expect($check['env'])->toBe(['PACKAGE' => $package()])
            ->and($result->successful())->toBe($passes, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'main' => ['0123456', true],
    'another commit' => ['89abcde', false],
    'no install line' => [null, false],
])->skip($skipOutsideKit, $outsideKit);

it('checks that a dist install ships the docs and leaves the kit-only files out (F50, M8b)', function () use ($step): void {
    $run = (string) ($step('release-check.yml', 'release-check', 'A dist install')['run'] ?? '');

    expect($run)->toContain('README.md LICENSE CHANGELOG.md docs/handleiding.html docs/superpowers/specs/')
        ->toContain('.github/workflows/release-check.yml docs/superpowers/plans')
        ->toContain('storage/app/private/skv-setup-token');
})->skip($skipOutsideKit, $outsideKit);
