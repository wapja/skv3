# M8b Publication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Part A (Tasks 1–3) is code and may run subagent-driven. Part B (Tasks 4–15) is the controller's: every step is outward-facing or needs the owner, and the controller runs it one command at a time in the main session, never in a workflow agent.

**Goal:** Publish skv3 as `frankcornet/skv3` on GitHub and Packagist, prove CI and the release check green there, and tag `v1.0.0`, each outward-facing step only after the owner's explicit approval.

**Architecture:** Part A is local and changes code: the export-ignored release-check workflow (CD-22 of M8a), the two kit-only paths a Packagist install leaves out, and the `Tests\Fixtures\KitRepository` marker that lets the kit-only tests skip in a generated app. Part A ends on branch `m8b-publication`, which the owner merges into `main` like every milestone. Part B publishes `main` and works only through GitHub (pull requests, Actions, rulesets), Packagist and the owner's Herd GUI. It follows DEC-36's Consequence (1)–(13) in that order, and records the outcome in a DEC-36 addendum.

**Tech Stack:** Laravel 13.35, Livewire 4, Flux 2, Pest 5.3, Symfony Yaml (workflow tests), GitHub Actions, the `gh` CLI 2.x, Packagist, Laravel Herd (Pro) and the Laravel installer 5.25.

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md` (§5.1 entry points, §8 CI and release-check, §10 docs, §11.2 M8, §12 success criteria). Decisions: `docs/decisions.md` DEC-36 (its Consequence lists M8b's thirteen steps; this plan follows them).

## Global Constraints

- **Package:** `frankcornet/skv3` (`"type": "project"`, MIT, public GitHub + Packagist) (spec header, DEC-09).
- §11.2 M8: GitHub and Packagist publication, the `v1.0.0` tag and the release-check job come **only after the owner's explicit approval**.
- §12 (1): Herd wizard → *Open site* → setup wizard → working app in under 5 minutes, measured from clicking *Create* to the dashboard, recorded once per configuration before release, with Herd Pro + Postgres and with free Herd + SQLite.
- §12 (4): `composer check` and the browser suite are green in CI. The e2e job (commit under test, Herd flag set) passes before publication, and release-check passes after.
- §8: all jobs but release-check live in `.github/workflows/tests.yml`. Release-check gets its own workflow file, `.github/workflows/release-check.yml`, which is `export-ignore`d and guarded by `github.repository`. It runs `laravel new --using=frankcornet/skv3` from Packagist and asserts that the installed reference equals `main` HEAD. It also repeats the kit's Flux Pro guard on the tag (D115).
- `livewire/flux-pro` and `composer.fluxui.dev` never appear in the committed or tagged `composer.json` / `composer.lock`.
- Every action in a workflow is pinned by full commit SHA with a `# vX.Y.Z` comment. Every checkout has `persist-credentials: false`, every job has `timeout-minutes` ≤ 60, and every workflow has `permissions: contents: read`.
- Never `git stash`, never rewrite published history, never force-push, never delete a test, never read `.env`, never `herd sites`.
- Secrets, licence keys, passwords and tokens are entered by the owner (`! gh auth login`, `! gh secret set …`, the Packagist and GitHub web UIs) and never appear in chat, in a command line Claude types, in the ledger or in a commit.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Pull request bodies end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Controller decisions

- **CD-1, two parts.** Part A (Tasks 1–3) is local code on `m8b-publication` and needs no approval: it changes nothing outside this machine. Part B (Tasks 4–15) publishes. The owner merges Part A into `main` before Task 5. What is published is `main` as merged.
- **CD-2, how a test knows it runs in the kit.** The Laravel installer keeps the kit's `composer.json` name in a generated app (`laravel/installer` 5.25 runs `composer create-project <kit> <dir> --stability=dev` and never rewrites `name`), so the name tells nothing. The marker is `.github/workflows/release-check.yml`: it is export-ignored, so no Packagist install has it. `Tests\Fixtures\KitRepository::isKit()` reads it. A test of kit-only metadata or of the release check skips when `isKit()` is false. In the kit's own repository, a missing marker fails CI (`tests.yml`, step *Kit keeps its release check*, `if: github.repository == 'frankcornet/skv3'`) instead of skipping quietly.
- **CD-3, what a Packagist install leaves out.** Exactly `/.github/workflows/release-check.yml` and `/docs/superpowers/plans` (13 milestone plans, 4.7 MB of build history no app reads; M8a final-review minor 13). The spec, the manual, `docs/decisions.md`, `scripts/traceability.php`, `tests.yml` and `.ai/skills` keep shipping: `TraceabilityTest` and `traceability.php` read the spec in every app. A gitattributes pattern does not match a trailing-slash directory form, so the lines name the directory without a slash; `git archive` then skips the whole tree (proved in the prototype: `git archive HEAD | tar -t` lists no `docs/superpowers/plans/` entry).
- **CD-4, the Packagist metadata pins in generated apps** (M8a final-review minor 10). `PackageMetadataTest`'s MIT-licence test and its Packagist-description test (keywords, homepage, support) skip outside the kit (CD-2). A generated app chooses its own licence and description. The shipped-files test and the new export-ignore test keep running everywhere: a generated app still ships what it points its users to.
- **CD-5, what the release check installs.** `laravel new --using=frankcornet/skv3` runs `composer create-project frankcornet/skv3 <dir> --stability=dev`. Composer then picks the default branch (`dev-main`), also after `v1.0.0` exists, so "the installed reference equals `main` HEAD" holds as §8 words it. The reference comes from Composer's `Installing frankcornet/skv3 (dev-main <sha>)` line in the installer's log. If the first dispatch (Task 12) shows Composer prints it differently, the fix-forward adapts the `sed` and its test in one pull request.
- **CD-6, approvals.** Every **[APPROVAL]** step is asked in chat with exactly what will happen, and runs only after the owner's explicit yes to that step. A yes never carries over to another step.
  - The approval for the first push (Task 6) names its scope: it also covers the pull requests, pushes and merges of the fix-forward loops in Tasks 7 and 12 and of the release pull request in Task 13. Those loops change only this repository through reviewed pull requests.
  - The repository, the secrets, the ruleset, Packagist, the tag and a GitHub release are each asked separately.
- **CD-7, history.** The published history is the local history as it is: 397 commits by one author, Frank Cornet, at a `@gemraad.nl` address. The milestone plans contain local paths such as `/Users/frankcornet/…`. Nothing is rewritten: rewriting is destructive and the owner has not asked for it. Task 4 names both facts in the publication approval, so the owner decides with them in view.
- **CD-8, branch protection.**
  - **Required checks:** one ruleset on `main` with no bypass actors. It requires a pull request with 0 approvals, because the owner is the only maintainer and GitHub does not let an author approve their own pull request. It blocks deletion and non-fast-forward updates. It requires these 11 checks, up to date with `main`: the three check rows, the browser job, `phase1` ×4 and `e2e` ×3.
  - **Required-but-skipped jobs:** `phase1` and `e2e` succeed on a pull request that does not touch the setup, because their path filter skips the steps, not the job. That makes them safe to require.
  - **After Task 10:** the owner too changes `main` only through pull requests.
- **CD-9, criterion 1.** The owner times *Create* → dashboard in the Herd GUI.
  - **Configuration B** is "free Herd + SQLite". The owner decides whether to stop Herd Pro's services for it, since that affects their other sites. If they keep them running, the addendum records configuration B as an approximation, as M8a did.
  - **Clean-up:** the controller removes the two sites and their databases afterwards (Task 11).
- **CD-10, the CHANGELOG cut.**
  - **Heading:** `## [1.0.0] - <tag date>`, with an empty `## [Unreleased]` above it.
  - **Links:** link definitions at the end of the file, `[Unreleased]: https://github.com/frankcornet/skv3/compare/v1.0.0...HEAD` and `[1.0.0]: https://github.com/frankcornet/skv3/releases/tag/v1.0.0`.
  - **Date:** the date is the day the owner approves the tag. If the tag slips to another day, the pull request is updated before it merges.
- **CD-11, pull requests.** Pull requests are merged with a merge commit (`gh pr merge <n> --merge --delete-branch`), like the kit's milestone merges. Before Task 10, a fix still goes through a pull request, so the loop is the same before and after the ruleset.
- **CD-12, logins.** `gh` is installed but not signed in. The owner signs in with `! gh auth login` (GitHub.com, HTTPS, browser) at the start of Task 5. Claude never handles the token.

## Owner actions in this plan

| Task | Action |
|---|---|
| 3 | Merge `m8b-publication` into `main` (or ask Claude to) |
| 4 | Approve publication itself, having read the facts it lists |
| 5 | `! gh auth login`; approve creating the repository |
| 6 | Approve the first push (and the fix-forward scope, CD-6) |
| 8 | Optional: `! gh secret set FLUX_USERNAME --repo frankcornet/skv3` and `! gh secret set FLUX_LICENSE_KEY --repo frankcornet/skv3` |
| 10 | Approve the ruleset |
| 11 | Approve Packagist, then submit the package and turn on the GitHub hook at packagist.org; time criterion 1 in the Herd GUI |
| 14 | Approve the tag; optionally approve a GitHub release |

## File ownership

| File | Task |
|---|---|
| `.github/workflows/release-check.yml` (create), `.gitattributes`, `.github/workflows/tests.yml` (one step), `tests/Fixtures/KitRepository.php` (create), `tests/Feature/Tooling/ReleaseCheckWorkflowTest.php` (create), `tests/Feature/Tooling/PackageMetadataTest.php` | 1 |
| `README.md`, `docs/handleiding.html`, `CHANGELOG.md`, `.ai/guidelines/skv3.md` (and the regenerated, git-ignored `AGENTS.md`), `docs/decisions.md` (the DEC-36 M8b addendum skeleton) | 2 |
| Fix-forward files (whatever the first CI runs name) | 7, 12 |
| `CHANGELOG.md` (the cut), `README.md` (status), `docs/handleiding.html` (lede, status, roadmap, limitations, colophon) | 13 |
| `docs/decisions.md` (filling the addendum) | 15 |

---

## Part A — local (no approval needed)

### Task 1: The release check, the kit marker and the kit-only paths (§8, §10, §12 (4); CD-2, CD-3, CD-4, CD-5; M8a final-review minors 10 and 13; D115)

Release-check gets its own export-ignored workflow (spec §8, CD-22 of M8a). Its tests must skip in a generated app, which never has the file, so the kit gets a marker fixture. CI makes the marker's absence a failure in the kit's own repository. The same marker moves the kit's Packagist metadata pins out of generated apps, and `.gitattributes` leaves the milestone plans out of a Packagist install.

**Files:**
- Create: `.github/workflows/release-check.yml`
- Create: `tests/Fixtures/KitRepository.php`
- Create: `tests/Feature/Tooling/ReleaseCheckWorkflowTest.php`
- Modify: `.gitattributes` (append the export-ignore block)
- Modify: `.github/workflows/tests.yml` (check job: a new step after *Kit commits no Flux Pro*)
- Modify: `tests/Feature/Tooling/PackageMetadataTest.php` (import, one new test, two `->skip()`)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `Tests\Fixtures\KitRepository::MARKER` (`'.github/workflows/release-check.yml'`) and `KitRepository::isKit(): bool`; the workflow `release-check` with one job `release-check`, whose steps are named *Checkout*, *Kit commits no Flux Pro*, *Setup PHP*, *Setup Node*, *Install the Laravel installer*, *laravel new from Packagist*, *The installed reference is main* and *A dist install*; the `tests.yml` check-job step *Kit keeps its release check*. Task 2 documents them; Tasks 10–12 use the job names.

- [ ] **Step 1: Write the marker fixture**

Create `tests/Fixtures/KitRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Whether the suite runs in the kit's own repository or in an app generated from it (M8b). The installer keeps the
 * kit's composer.json name, so the name tells nothing; the release check's workflow does: it is export-ignored, so a
 * Packagist install never has it. The kit's CI fails when the file goes missing (tests.yml, "Kit keeps its release
 * check"), so a test that skips outside the kit cannot hide a lost file in the kit.
 */
final class KitRepository
{
    public const string MARKER = '.github/workflows/release-check.yml';

    public static function isKit(): bool
    {
        return is_file(base_path(self::MARKER));
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Tooling/ReleaseCheckWorkflowTest.php`:

```php
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

it('repeats the kit\'s Flux Pro guard on the released commit (D115)', function () use ($step): void {
    expect($step('release-check.yml', 'release-check', 'Kit commits no Flux Pro')['run'] ?? null)
        ->toBe($step('tests.yml', 'check', 'Kit commits no Flux Pro')['run'] ?? false);
})->skip($skipOutsideKit, $outsideKit);

it('installs the published package as a user does, never the commit under test (§8)', function () use ($step, $package): void {
    $install = (string) ($step('release-check.yml', 'release-check', 'laravel new from Packagist')['run'] ?? '');

    expect($install)->toContain("laravel new app --using={$package()} --no-interaction")
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
```

Replace `tests/Feature/Tooling/PackageMetadataTest.php` with:

```php
<?php

declare(strict_types=1);

/** covers §10 */

use Tests\Fixtures\KitRepository;

/**
 * The patterns .gitattributes marks export-ignore: what a dist install from Packagist (a GitHub zipball, Composer's
 * default) leaves out (F50).
 *
 * @return list<string>
 */
$exportIgnored = function (): array {
    $patterns = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.gitattributes'))) ?: [] as $line) {
        $fields = preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($fields !== [] && ! str_starts_with($fields[0], '#') && in_array('export-ignore', array_slice($fields, 1), true)) {
            $patterns[] = ltrim($fields[0], '/');
        }
    }

    return $patterns;
};

/** @return array<string, mixed> */
$composer = function (): array {
    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    return $composer;
};

it('ships every file the app points its users to in a Packagist install (F50)', function () use ($exportIgnored): void {
    $patterns = $exportIgnored();
    $ignored = fn (string $file): bool => array_any($patterns, fn (string $pattern): bool => fnmatch($pattern, $file) || str_starts_with($file, rtrim($pattern, '/').'/'));
    // The README holds the production checklist BootstrapCommand sends a production install to; the MIT notice must
    // travel with every copy; the others are what the docs, the e2e job and a new app's CI read.
    $shipped = ['README.md', 'LICENSE', 'CHANGELOG.md', 'docs/handleiding.html', '.env.example', 'composer.json', 'composer.lock', '.github/workflows/tests.yml', 'tests/Fixtures/e2e-answers.json'];

    expect((string) file_get_contents(app_path('Console/Commands/BootstrapCommand.php')))->toContain('production checklist in the README')
        ->and(array_values(array_filter($shipped, fn (string $file): bool => ! is_file(base_path($file)))))->toBe([])
        ->and(array_values(array_filter($shipped, $ignored)))->toBe([]);
});

it('leaves only the kit-only files out of a Packagist install (M8b)', function () use ($exportIgnored): void {
    // The release check runs in the kit's repository alone, and the milestone plans are its build history; the spec
    // stays, because scripts/traceability.php and TraceabilityTest read it in every app.
    expect($exportIgnored())->toBe(['.github/workflows/release-check.yml', 'docs/superpowers/plans']);
});

it('ships the MIT licence composer.json declares (F59, CD-20)', function () use ($composer): void {
    $license = is_file(base_path('LICENSE')) ? (string) file_get_contents(base_path('LICENSE')) : '';

    expect($composer()['license'] ?? null)->toBe('MIT')
        ->and($license)->toStartWith("MIT License\n\nCopyright (c) ")
        ->toContain('Permission is hereby granted, free of charge, to any person obtaining a copy')
        ->toContain('The above copyright notice and this permission notice shall be included in all')
        ->toContain('THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND');
})->skip(fn (): bool => ! KitRepository::isKit(), 'An app generated from the kit chooses its own licence.');

it('describes the package for Packagist, with links built from its own name (F58)', function () use ($composer): void {
    $package = $composer();
    // Built from composer.json's name: NameLeakTest scans tests/, so this file holds no kit name literal.
    $repository = 'https://github.com/'.$package['name'];

    expect($package['type'] ?? null)->toBe('project')
        ->and($package['description'] ?? '')->not->toBe('')
        ->and($package['keywords'] ?? null)->toBe(['laravel', 'starter-kit', 'multi-tenant', 'livewire', 'flux', 'saas'])
        ->and($package['homepage'] ?? null)->toBe($repository)
        ->and($package['support'] ?? null)->toBe(['issues' => $repository.'/issues', 'source' => $repository]);
})->skip(fn (): bool => ! KitRepository::isKit(), 'An app generated from the kit describes itself.');
```

- [ ] **Step 3: Run them to see them fail or skip for the right reason**

Run: `vendor/bin/pest tests/Feature/Tooling/ReleaseCheckWorkflowTest.php tests/Feature/Tooling/PackageMetadataTest.php`

Expected:
- FAIL `fails the kit's CI when the release check goes missing`, with "tests.yml check has no single step named [Kit keeps its release check]".
- FAIL `leaves only the kit-only files out of a Packagist install`, with `[]` ≠ the two paths.
- The release-check tests and the two metadata tests are SKIPPED with their reason, because the marker does not exist yet. A skip is not a pass: Step 5 makes them run.

- [ ] **Step 4: Add the CI guard and the export-ignore block**

In `.github/workflows/tests.yml`, check job, insert after the *Kit commits no Flux Pro* step (before *Setup Node*):

```yaml
      # M8b: a generated app has no release-check.yml (export-ignored), so the tests that read it skip there
      # (Tests\Fixtures\KitRepository); in the kit's own repository a missing file fails here instead of skipping.
      - name: Kit keeps its release check
        if: github.repository == 'frankcornet/skv3'
        run: |
          test -f .github/workflows/release-check.yml || { echo "FAIL: .github/workflows/release-check.yml is missing"; exit 1; }
```

Keep `run: |` as a block: a one-line `run:` whose text holds `: ` is invalid YAML, and every test that parses `tests.yml` then fails (seen in the prototype).

Append to `.gitattributes`:

```gitattributes
# Kit-only files a Packagist (dist) install leaves out (M8b): the release check runs in the kit's own repository only,
# and the milestone plans are the kit's build history, not something an app reads.
/.github/workflows/release-check.yml export-ignore
/docs/superpowers/plans export-ignore
```

- [ ] **Step 5: Create the release-check workflow**

Create `.github/workflows/release-check.yml`:

```yaml
name: release-check

# §8, CD-22 (M8a): after a release, install the published package the way a user does and check it is main. This file
# is export-ignored (.gitattributes), so an app generated from the kit never gets it, and the job runs in the kit's own
# repository only. It installs from Packagist, never the commit under test: the e2e job in tests.yml covers that.
on:
  release:
    types: [published]
  push:
    tags: ['v*']
  workflow_dispatch:

permissions:
  contents: read

# A tag push and the release published for it start one run each; the later one replaces the earlier.
concurrency:
  group: release-check-${{ github.ref }}
  cancel-in-progress: true

jobs:
  release-check:
    name: release-check
    if: github.repository == 'frankcornet/skv3'
    runs-on: ubuntu-latest
    timeout-minutes: 30

    defaults:
      run:
        shell: bash

    steps:
      - name: Checkout
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          fetch-depth: 0
          persist-credentials: false

      # D115: the tagged commit never names Flux Pro either; the same lines as tests.yml's check job.
      - name: Kit commits no Flux Pro
        run: |
          committed="$(git show HEAD:composer.json HEAD:composer.lock)"
          if grep -nE 'flux-pro|composer\.fluxui\.dev' <<< "$committed"; then
            echo "FAIL: the committed composer.json or composer.lock names Flux Pro"
            exit 1
          fi
          git show HEAD:composer.json | php -r '$c = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(isset($c["extra"]["skv"]) ? 1 : 0);' || { echo "FAIL: the committed composer.json carries extra.skv"; exit 1; }

      - name: Setup PHP
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.4'
          extensions: pdo_sqlite, gd, zip
          tools: composer:v2
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '22'

      # The installer commits the new app (--git); the runner has no git identity of its own.
      - name: Install the Laravel installer
        run: |
          git config --global user.name "release-check"
          git config --global user.email "release-check@example.com"
          composer global require laravel/installer --no-interaction
          echo "$(composer global config bin-dir --absolute)" >> "$GITHUB_PATH"

      # Herd's exact command (§8), from Packagist: no local repository, no version constraint.
      - name: laravel new from Packagist
        run: |
          cd "$RUNNER_TEMP" && laravel new app --using=frankcornet/skv3 --no-interaction --no-ansi --database=sqlite --npm --pest --git --boost 2>&1 | tee install.log

      # §8: the installed reference equals main's HEAD. The installer runs `composer create-project --stability=dev`,
      # which picks the default branch, and Composer's log names its commit.
      - name: The installed reference is main
        working-directory: ${{ runner.temp }}
        env:
          PACKAGE: frankcornet/skv3
        run: |
          installed="$(sed -nE "s#.*Installing ${PACKAGE} \(dev-main ([0-9a-f]{7,40})\).*#\1#p" install.log | head -n 1)"
          main="$(git -C "$GITHUB_WORKSPACE" ls-remote origin refs/heads/main | cut -f1)"
          [ -n "$installed" ] || { echo "FAIL: install.log names no dev-main reference of ${PACKAGE}"; exit 1; }
          [ -n "$main" ] || { echo "FAIL: origin has no main"; exit 1; }
          case "$main" in
            "$installed"*) echo "installed ${installed} is main ${main}" ;;
            *) echo "FAIL: installed ${installed}, main is ${main}"; exit 1 ;;
          esac

      # F50, M8b: a dist install ships what the app points its users to and leaves the kit-only files out.
      - name: A dist install
        working-directory: ${{ runner.temp }}/app
        run: |
          for file in README.md LICENSE CHANGELOG.md docs/handleiding.html docs/superpowers/specs/2026-10-10-skv3-design.md; do
            test -f "$file" || { echo "FAIL: ${file} is missing"; exit 1; }
          done
          for path in .github/workflows/release-check.yml docs/superpowers/plans; do
            if [ -e "$path" ]; then echo "FAIL: ${path} shipped"; exit 1; fi
          done
          test -f storage/app/private/skv-setup-token || { echo "FAIL: the setup token is missing"; exit 1; }
          php artisan route:list --path=welcome-setup --no-ansi | grep -q 'welcome-setup' || { echo "FAIL: the wizard route is missing"; exit 1; }
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `vendor/bin/pest tests/Feature/Tooling`
Expected: PASS, nothing skipped. The prototype reported 121 passed and 818 assertions on `470d0de` plus this task.

- [ ] **Step 7: Prove the skips and the mutations**

Each check uses `cp` to park a file and `cp` to restore it. Never `git stash`.

| Change | Expected |
|---|---|
| Move `release-check.yml` aside (a dist install) | `vendor/bin/pest tests/Feature/Tooling`: passes with 9 skipped (7 release-check cases with the dataset, 2 metadata tests); the guard-step test still runs and passes |
| In the reference step, `"$installed"*) echo` → `*) echo` | `passes only when the installed reference is main's HEAD` fails on *another commit* |
| In release-check's Flux Pro guard, `flux-pro\|composer` → `flux-pro-x\|composer` | `repeats the kit's Flux Pro guard` fails |
| Delete the `/docs/superpowers/plans export-ignore` line | `leaves only the kit-only files out` fails |
| Delete the *Kit keeps its release check* step | `fails the kit's CI when the release check goes missing` fails |

Then the archive check, after the commit in Step 9:
`git archive HEAD | tar -t | grep -cE '^docs/superpowers/plans/|release-check\.yml'` prints `0`, and `git archive HEAD | tar -t | grep -cE '^(README\.md|LICENSE|CHANGELOG\.md|docs/superpowers/specs/2026-10-10-skv3-design\.md|\.github/workflows/tests\.yml)$'` prints `5`.

- [ ] **Step 8: The task gate**

```bash
vendor/bin/pint --test tests/Fixtures/KitRepository.php tests/Feature/Tooling/ReleaseCheckWorkflowTest.php tests/Feature/Tooling/PackageMetadataTest.php
vendor/bin/rector process --dry-run tests/Fixtures/KitRepository.php tests/Feature/Tooling/ReleaseCheckWorkflowTest.php tests/Feature/Tooling/PackageMetadataTest.php
vendor/bin/phpstan analyse tests/Fixtures/KitRepository.php tests/Feature/Tooling/ReleaseCheckWorkflowTest.php tests/Feature/Tooling/PackageMetadataTest.php
vendor/bin/pest tests/Arch tests/Feature/Tooling
```

Expected: all clean (the prototype needed one Rector fix, already applied above: no `@var` before `return $found[0]`).

- [ ] **Step 9: Commit**

```bash
git add .github/workflows/release-check.yml .github/workflows/tests.yml .gitattributes tests/Fixtures/KitRepository.php tests/Feature/Tooling/ReleaseCheckWorkflowTest.php tests/Feature/Tooling/PackageMetadataTest.php
git commit -m "ci: add the export-ignored release check and keep the kit-only files out of a packagist install

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 2: Document the release check and the kit marker; open the DEC-36 M8b addendum (§10; CD-2–CD-5)

**Files:**
- Modify: `README.md` (§ *Develop the kit*, the paragraph after the command block)
- Modify: `docs/handleiding.html` (the CI bullet in the developers' chapter, about line 1638)
- Modify: `CHANGELOG.md` (`## [Unreleased]`: one *Added* and one *Changed* line)
- Modify: `.ai/guidelines/skv3.md` (one rule), then regenerate `AGENTS.md`
- Modify: `docs/decisions.md` (append the M8b addendum to DEC-36)

**Interfaces:**
- Consumes: Task 1's names (`KitRepository::isKit()`, `release-check.yml`, the step *Kit keeps its release check*).
- Produces: the addendum markers `(filled in after publication)`, which Task 15 fills.

- [ ] **Step 1: README**

In `README.md`, § *Develop the kit*, append this sentence to the end of the paragraph that starts "The tests never touch your development database":

```markdown
A Packagist install leaves out `.github/workflows/release-check.yml` (the kit's own release check, which installs the published package and checks it is `main`) and the milestone plans under `docs/superpowers/plans`; the tests that read kit-only files skip in an app generated from the kit (`Tests\Fixtures\KitRepository`), and the kit's CI fails when the release check goes missing.
```

- [ ] **Step 2: Manual**

In `docs/handleiding.html`, the CI bullet that begins `<li>CI (<code>.github/workflows/tests.yml</code>) draait drie controlerijen`: insert this before its closing `</li>`:

```html
 De repository van de kit heeft ook een eigen release-check (<code>.github/workflows/release-check.yml</code>): na een release installeert hij het pakket van Packagist zoals een gebruiker dat doet en controleert hij dat het <code>main</code> is. Een installatie via Packagist krijgt dat bestand en de milestoneplannen onder <code>docs/superpowers/plans</code> niet mee; de tests die kit-bestanden lezen, slaan zichzelf daar over (<code>Tests\Fixtures\KitRepository</code>).
```

- [ ] **Step 3: CHANGELOG**

Under `## [Unreleased]` → `### Added`, append:

```markdown
- A release check of its own (`.github/workflows/release-check.yml`, in the kit's repository only): after a release, on a tag push or on demand it installs the published package from Packagist the way a user does, checks the installed commit is `main`, that a Packagist install ships the README, licence, changelog, manual and spec and leaves out the kit-only files, and that the released `composer.json` and `composer.lock` name no Flux Pro.
```

Under `### Changed`, append:

```markdown
- A Packagist install no longer ships the kit's milestone plans (`docs/superpowers/plans`) or its release check, and in an app generated from the kit the tests of the kit's own licence and Packagist description skip, so the app chooses its own.
```

- [ ] **Step 4: Kit guideline**

In `.ai/guidelines/skv3.md`, add this line after the bullet about test files that never declare classes:

```markdown
- A test of a kit-only file or of the kit's own package metadata skips outside the kit with `->skip(fn (): bool => ! \Tests\Fixtures\KitRepository::isKit(), '…')`; never tell the kit from a generated app by `composer.json`'s name, which the installer keeps.
```

Regenerate `AGENTS.md` with `php artisan boost:update --no-discover --no-interaction`, as M8a's Task 17 did. `AGENTS.md` is git-ignored, so it is not committed. Check that `grep -c KitRepository AGENTS.md` prints `1`.

- [ ] **Step 5: DEC-36 addendum skeleton**

Append to the end of `docs/decisions.md` (DEC-36 is its last entry, a `###` heading that ends with its **Consequence:** paragraph), after one blank line:

```markdown
**Addendum (M8b, publication):** Part A (`docs/superpowers/plans/2026-10-18-m8b-publication.md` Tasks 1–3) added the export-ignored release check, `Tests\Fixtures\KitRepository` (the kit is the repository that has `release-check.yml`, because the installer keeps `composer.json`'s name; the kit's CI fails when it is missing) and left `docs/superpowers/plans` out of a Packagist install; `PackageMetadataTest`'s licence and Packagist pins skip in a generated app (M8a final-review minors 10 and 13). The approvals and their dates: (filled in after publication). The first CI runs and the fix-forward pull requests: (filled in after publication). The ruleset: (filled in after publication). Packagist and the dist install: (filled in after publication). Criterion 1 in the Herd GUI: (filled in after publication). The release check and `v1.0.0`: (filled in after publication).
```

- [ ] **Step 6: Gate and commit**

Run: `vendor/bin/pest tests/Feature/Tooling tests/Feature/LangCoverageTest.php` → PASS (nothing in this task adds a `__()` key).

```bash
git add README.md docs/handleiding.html CHANGELOG.md .ai/guidelines/skv3.md docs/decisions.md
git commit -m "docs: describe the release check and the kit marker, and open the m8b addendum

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: Part A gate and hand-over (controller)

- [ ] **Step 1: Full gate**

```bash
git status --short
composer check
REQUIRE_POSTGRES=true composer test:pgsql
php artisan config:clear && MODULE_IMPERSONATION=false MODULE_PASSKEYS=false vendor/bin/pest --parallel --exclude-group=browser
npm run build && composer test:browser
npm run build:setup && git diff --exit-code resources/setup/setup.css
php scripts/traceability.php
composer validate --strict
git diff main --stat -- composer.json composer.lock package.json package-lock.json
grep -rnE "(^|[[ ,(])['\"]\*['\"] *=>|Process::fake\(\)" tests/ | grep -v -e KitRulesTest -e StrayProcessGuardTest
```

Expected: a clean tree; every suite green; `traceability.php` 90/90/0/0; `composer validate --strict` valid; no package-file change; no catch-all fake.

- [ ] **Step 2: Hand-over**

Report Part A to the owner and stop. The owner merges `m8b-publication` into `main` (`git merge --no-ff m8b-publication -m "Merge M8b part A: release check"`), or asks Claude to. Part B starts from that `main`.

---

## Part B — publication (controller; [APPROVAL] steps wait for the owner's explicit yes)

`SCRATCH` is the controller's session scratchpad directory (`m8b/` in it). Every command in Part B runs in the main session, one at a time, and its result goes into the ledger `.superpowers/sdd/2026-10-18-m8b-publication/progress.md`. Never record a token, a secret or a licence key there.

### Task 4: Pre-flight and **[APPROVAL] publication itself** (DEC-36 Consequence (1); CD-6, CD-7)

- [ ] **Step 1: Scan the history for secrets (read-only)**

```bash
git log --all -p --no-color | grep -nE 'APP_KEY=base64:[A-Za-z0-9+/=]{20,}|BEGIN ([A-Z]+ )?PRIVATE KEY|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|FLUX_LICENSE_KEY=[^ "$]+|password[\x27"]? *[:=>]+ *[\x27"][^\x27"]{12,}' | grep -v -e 'APP_KEY=base64:$' | head -50
git log --all --diff-filter=A --name-only --format= | grep -E '(^|/)(\.env(\..+)?|auth\.json|.+\.(pem|key|p12|sqlite))$' | grep -v -e '^\.env\.example$' | sort -u
```

Expected: no line that is a real secret. Every hit is either a test fixture that is visibly fake or a documented example. Name each hit and say why it is harmless. The only `.env*` file ever added is `.env.example`. A real secret stops Part B: report it to the owner, because removing it from history is a rewrite the owner must decide on.

- [ ] **Step 2: Facts for the approval**

```bash
git rev-list --count main
git log main --format='%an <%ae>' | sort | uniq -c | sed -E 's/<[^@]+@/<…@/'
grep -rlE '/Users/[a-z]+/' docs/superpowers/plans | wc -l
du -sh .git
```

- [ ] **Step 3: Ask** ("[APPROVAL 1/7] Publication")

Ask the owner, in Dutch, with the facts from Step 2:
- The repository `frankcornet/skv3` becomes public, with the full history: the commit count, one author, the author address domain `@gemraad.nl` (shown whole on GitHub), and the plan files with local paths.
- The licence is MIT.
- The next steps each come with their own question.

Wait for an explicit yes. Record the date and the wording of the answer in the ledger.

### Task 5: **[APPROVAL] the GitHub repository** (Consequence (2); CD-12)

- [ ] **Step 1: Owner signs in**

Ask the owner to run `! gh auth login` (GitHub.com, HTTPS, sign in with a browser). Then run `gh auth status`. It must name the account `frankcornet` and the scopes `repo` and `workflow`. Never print the token. If `workflow` is missing, ask for `! gh auth refresh -s workflow`.

- [ ] **Step 2: Check the name is free**

`gh repo view frankcornet/skv3 --json name 2>&1 | head -1` should report that the repository is not found.

- [ ] **Step 3: Ask** ("[APPROVAL 2/7] Repository")

Ask: create the public repository `frankcornet/skv3` with the description "Multi-tenant Laravel starter kit: organisations on subdomains, a setup wizard, Livewire 4 and Flux", no README, licence or gitignore of GitHub's own; mark it as a template; no push yet.

- [ ] **Step 4: Create**

```bash
gh repo create frankcornet/skv3 --public --description "Multi-tenant Laravel starter kit: organisations on subdomains, a setup wizard, Livewire 4 and Flux"
gh repo edit frankcornet/skv3 --template --enable-wiki=false --enable-projects=false --delete-branch-on-merge
git remote add origin https://github.com/frankcornet/skv3.git
gh repo view frankcornet/skv3 --json visibility,isTemplate,defaultBranchRef
```

Expected: `PUBLIC`, `isTemplate: true`, and no default branch yet.

### Task 6: **[APPROVAL] push `main`** (Consequence (3); CD-6)

- [ ] **Step 1: Ask** ("[APPROVAL 3/7] Push")

Ask: push `main` (the commit hash, no tags) to `origin`. Name the scope from CD-6: the same yes covers the pull requests, pushes and merges of the fix-forward loops (Tasks 7 and 12) and of the release pull request (Task 13), and nothing else.

- [ ] **Step 2: Push**

```bash
git push -u origin main
gh repo view frankcornet/skv3 --json defaultBranchRef --jq .defaultBranchRef.name
gh run list --repo frankcornet/skv3 --limit 5
```

Expected: `main`; one `ci` run started by the push.

### Task 7: The first CI runs and the fix-forward loop (Consequence (5))

- [ ] **Step 1: Watch the push run**

Run `gh run watch <id> --repo frankcornet/skv3 --exit-status`, then `gh run view <id> --repo frankcornet/skv3 --log-failed | tail -200` for a failure. A push to `main` runs `check` ×3 and `browser`. `phase1` and `e2e` run, but their path filter skips their steps (`run=false` outside `schedule` and `workflow_dispatch`). Expected on the three check rows:
- `REQUIRE_POSTGRES=true` on the PostgreSQL row;
- `BackupRunTest` and the PostgreSQL wizard test running, not skipped (search the log for them);
- *Kit commits no Flux Pro* and *Kit keeps its release check* ran (the `if` held);
- the Pro steps skipped without secrets.

- [ ] **Step 2: Dispatch phase1 and e2e**

```bash
gh workflow run ci --repo frankcornet/skv3 --ref main
gh run list --repo frankcornet/skv3 --workflow ci --event workflow_dispatch --limit 1
```

Watch the run. Expected: `phase1` ×4 (terminal, terminal-nodb, composer, clone) and `e2e` ×3 (default, phpunit-no-boost, postgres-pro) green; the `phpunit-no-boost` row's `tests/` diff and `boost:update` count pass.

- [ ] **Step 3: The browser job's hosts**

If the browser job fails because `*.localhost` does not resolve on ubuntu, add A82's fallback in a fix-forward pull request:
- **The hosts:** list them with `grep -rhoE "[a-z0-9-]+\.localhost" tests/Browser tests/Pest.php | sort -u`.
- **The step:** add a step before the browser tests that appends `127.0.0.1 <host>` for each host to `/etc/hosts` with `sudo tee -a`.
- **The test:** a `CiWorkflowTest` case pins the step and the host list.

Do this only on a real failure. ubuntu-latest's systemd-resolved normally answers `*.localhost`.

- [ ] **Step 4: The fix-forward protocol** (for any failure in Steps 1–3)

For each failure:
1. Find the root cause first (superpowers:systematic-debugging).
2. Create a branch `ci/<n>-<slug>`. Write a failing test where a test can catch it (`CiWorkflowTest`, `ReleaseCheckWorkflowTest` or the suite the failure is in), then the fix.
3. Run the local gate for the files touched.
4. `git push -u origin ci/<n>-<slug>`, then `gh pr create --repo frankcornet/skv3 --base main --title "<conventional title>" --body "<cause, fix, evidence>\n\n🤖 Generated with [Claude Code](https://claude.com/claude-code)"`.
5. Wait for every check to pass, then `gh pr merge <n> --repo frankcornet/skv3 --merge --delete-branch` and `git switch main && git pull --ff-only`.

Each round goes into the ledger. Stop and report to the owner after five rounds on the same failure.

- [ ] **Step 5: A real pull request for the path filter and the kit guard**

The first fix-forward pull request counts if it touches a path the e2e filter names. Otherwise open one on `ci/1-first-pr` that changes `scripts/e2e-local.sh` by one comment line only: `# Local counterpart of the e2e and phase1 jobs; see .github/workflows/tests.yml.` after the header block. Expected on the pull request:
- `phase1` and `e2e` run their steps, because the filter matched `scripts/e2e-local.sh`;
- *Kit commits no Flux Pro* and *Kit keeps its release check* ran;
- all 11 checks are green.

Merge it as in Step 4.

### Task 8: Optional — the Flux Pro secrets (Consequence (4); A83's CI half)

- [ ] **Step 1: Ask** ("[APPROVAL 4/7] Flux Pro secrets, optional")

Ask whether the owner wants the Pro rows to run. If yes, the owner runs both commands themselves. Each prompts for the value, which never reaches the chat:
- `! gh secret set FLUX_USERNAME --repo frankcornet/skv3`
- `! gh secret set FLUX_LICENSE_KEY --repo frankcornet/skv3`

If no, record "no Pro secrets" and skip to Task 9.

- [ ] **Step 2: Prove the Pro steps**

`gh secret list --repo frankcornet/skv3` names both secrets and shows no values. Dispatch `ci` again (Task 7 Step 2). Expected:
- the PostgreSQL check row runs *Enable Flux Pro* and `ProVariantsTest` passes;
- the `postgres-pro` e2e row runs *Enable Flux Pro*;
- no log line shows the licence. GitHub masks secrets; also search the log for `FLUX_LICENSE_KEY=` to confirm it is never echoed.

### Task 9: CI green on main

- [ ] **Step 1:** `gh run list --repo frankcornet/skv3 --branch main --limit 3` shows the latest push run and the latest dispatch run both `success` on the same head as `git rev-parse main`. Record the run URLs.

### Task 10: **[APPROVAL] branch protection** (Consequence (6); CD-8)

- [ ] **Step 1: Write the ruleset**

Write the ruleset to the scratchpad as `ruleset.json` (not in the repository):

```json
{
  "name": "main",
  "target": "branch",
  "enforcement": "active",
  "bypass_actors": [],
  "conditions": { "ref_name": { "include": ["~DEFAULT_BRANCH"], "exclude": [] } },
  "rules": [
    { "type": "deletion" },
    { "type": "non_fast_forward" },
    {
      "type": "pull_request",
      "parameters": {
        "required_approving_review_count": 0,
        "dismiss_stale_reviews_on_push": false,
        "require_code_owner_review": false,
        "require_last_push_approval": false,
        "required_review_thread_resolution": false
      }
    },
    {
      "type": "required_status_checks",
      "parameters": {
        "strict_required_status_checks_policy": true,
        "required_status_checks": [
          { "context": "main (PHP 8.4, PostgreSQL)", "integration_id": 15368 },
          { "context": "sqlite-free (PHP 8.4, SQLite)", "integration_id": 15368 },
          { "context": "php85 (PHP 8.5, SQLite)", "integration_id": 15368 },
          { "context": "browser (PHP 8.4, SQLite, Chromium)", "integration_id": 15368 },
          { "context": "phase1 (terminal)", "integration_id": 15368 },
          { "context": "phase1 (terminal-nodb)", "integration_id": 15368 },
          { "context": "phase1 (composer)", "integration_id": 15368 },
          { "context": "phase1 (clone)", "integration_id": 15368 },
          { "context": "e2e (default)", "integration_id": 15368 },
          { "context": "e2e (phpunit-no-boost)", "integration_id": 15368 },
          { "context": "e2e (postgres-pro)", "integration_id": 15368 }
        ]
      }
    }
  ]
}
```

`15368` is the GitHub Actions app. Before asking, check that every context matches a check name from the last run: `gh run view <run> --repo frankcornet/skv3 --json jobs --jq '.jobs[].name' | sort`.

- [ ] **Step 2: Ask** ("[APPROVAL 5/7] Ruleset")

Ask: apply the ruleset on `main`. Name its effect: no direct push for anyone, the owner included; pull requests only, with 0 approvals; no force push and no deletion; the 11 checks required and up to date with `main`.

- [ ] **Step 3: Apply and verify**

```bash
gh api -X POST repos/frankcornet/skv3/rulesets --input ruleset.json --jq '.id, .enforcement'
gh api repos/frankcornet/skv3/rules/branches/main --jq '.[].type' | sort
```

Expected: `active`; then `deletion`, `non_fast_forward`, `pull_request`, `required_status_checks`.

### Task 11: **[APPROVAL] Packagist**, the dist install and criterion 1 (Consequence (7), (8), (9); CD-9)

- [ ] **Step 1: Ask** ("[APPROVAL 6/7] Packagist")

Ask: publish `frankcornet/skv3` on Packagist. The owner does it at packagist.org:
1. Sign in with GitHub.
2. *Submit* → `https://github.com/frankcornet/skv3`.
3. In the Packagist profile, turn on the GitHub integration, so Packagist updates on every push.

Claude never signs in to Packagist.

- [ ] **Step 2: Verify (read-only, public URLs)**

```bash
curl -s https://repo.packagist.org/p2/frankcornet/skv3~dev.json | jq -r '.packages["frankcornet/skv3"][] | select(.version == "dev-main") | .source.reference'
git rev-parse main
```

Expected: the same hash. After one more merge to `main`, the reference follows within minutes, which shows the hook works.

- [ ] **Step 3: A real dist install (Consequence (8))**

```bash
cd "$HOME/Herd" && laravel new m8b-packagist --using=frankcornet/skv3 --no-interaction --database=sqlite --npm --pest --git --boost 2>&1 | tee "$SCRATCH"/packagist-install.log
grep -E 'Installing frankcornet/skv3 \(dev-main [0-9a-f]+\)' "$SCRATCH"/packagist-install.log
cd "$HOME/Herd/m8b-packagist" && ls README.md LICENSE CHANGELOG.md docs/handleiding.html && test ! -e docs/superpowers/plans && test ! -e .github/workflows/release-check.yml && echo "dist contents ok"
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://m8b-packagist.test/
cd "$HOME/Herd/m8b-packagist" && php artisan config:clear && vendor/bin/pest tests/Feature/Tooling --compact
```

Expected:
- the `Installing … (dev-main <hash of main>)` line, which confirms CD-5's format before Task 12;
- `dist contents ok`;
- `302` to `/welcome-setup?token=…`, not recorded;
- the Tooling tests pass, with the kit-only cases skipped and their reason printed.

Clean-up:
- `rm -rf "$HOME/Herd/m8b-packagist"`;
- `herd unsecure m8b-packagist` only if `herd secured` lists it;
- the Herd Pro mailbox is the owner's to remove.

- [ ] **Step 4: Criterion 1 in the Herd GUI (owner)**

Ask the owner to time two runs in the Herd app, each from clicking *Create* to the dashboard, with every pre-filled answer kept:
- **A:** open `https://herd.laravel.com/new/frankcornet/skv3` (the deep link), name the site `m8b-gui-a`, keep PostgreSQL;
- **B:** *New site* → *Custom Starter Kit* → `frankcornet/skv3`, name the site `m8b-gui-b`, answer SQLite in the wizard (CD-9: the owner decides about Herd Pro's services).

Record the two durations, and whether B ran with Herd Pro's services stopped.

Clean up both sites afterwards:
- `herd unsecure` each;
- `rm -rf` each;
- drop `m8b_gui_a` and `m8b_gui_a_test` with M8a's PDO script pattern, by literal name after `pg_database` lists exactly them;
- uninstall the project-scoped plugin with `claude plugin uninstall superpowers@superpowers-marketplace --scope project --keep-data` inside each site first.

### Task 12: The release check green on dispatch (Consequence (10))

- [ ] **Step 1:**

```bash
gh workflow run release-check --repo frankcornet/skv3 --ref main
gh run list --repo frankcornet/skv3 --workflow release-check --limit 1
```

Watch it. Expected: every step green, and *The installed reference is main* prints `installed <short> is main <full>`.

A failure goes through Task 7's fix-forward protocol, with the test in `ReleaseCheckWorkflowTest`. Example: Composer printing the install line differently, which CD-5 foresees. Then dispatch again.

### Task 13: The release pull request (Consequence (11); CD-10)

- [ ] **Step 1: Branch and date**

`git switch -c release/1.0.0 main`. The date is `date +%F` on the day of Task 14's approval. If the tag slips, update the date before the merge.

- [ ] **Step 2: CHANGELOG**

In `CHANGELOG.md`:
- Replace the line `## [Unreleased]` with this, so everything under it becomes 1.0.0:

  ```markdown
  ## [Unreleased]

  ## [1.0.0] - <date>
  ```

- Append to the end of the file:

  ```markdown

  [Unreleased]: https://github.com/frankcornet/skv3/compare/v1.0.0...HEAD
  [1.0.0]: https://github.com/frankcornet/skv3/releases/tag/v1.0.0
  ```

- [ ] **Step 3: README status line**

Replace the status line (line 5) with:

```markdown
> **Status:** v1.0.0, released <date>: published on GitHub and Packagist as `frankcornet/skv3`. The design is in `docs/superpowers/specs/2026-10-10-skv3-design.md`, the decisions are in `docs/decisions.md` and the changes in `CHANGELOG.md`.
```

- [ ] **Step 4: Manual**

In `docs/handleiding.html`:
- **The lede:** replace `Geschreven voor de stand na milestone M8a: klaar voor de release van v1.0.0.` with `Geschreven voor v1.0.0.`.
- **Chapter 7's opening paragraph:** replace it with `<p>Milestones M0 tot en met M8b zitten in <code>main</code>. De kit is uitgebracht als v1.0.0 (<date>) en staat op GitHub en Packagist als <code>frankcornet/skv3</code>; wat er in die versie zit, staat onder <code>[1.0.0]</code> in de changelog.</p>`.
- **The status table:** after the M8a row, add `<tr><td class="tabnum">M8b</td><td>Publicatie: de repository op GitHub, CI groen (ook <code>phase1</code> en <code>e2e</code>), de beveiliging van <code>main</code>, het pakket op Packagist, de Herd-deeplink gecontroleerd en de installatie in Herd gemeten, de release-check en de tag <code>v1.0.0</code></td><td><span class="badge b-ok">klaar</span></td></tr>`.
- **The roadmap table:** replace its only row with `<tr><td><strong>Na v1</strong></td><td>De punten die DEC-36 voor na v1 noemt, elk met de reden waarom het kan wachten</td></tr>`.
- **The known limitations:** remove the first bullet, which begins `Installeren vanuit de Herd-app, met de deeplink`.
- **The colophon:** replace `na milestone M8a` with `bij v1.0.0`.

- [ ] **Step 5: Pull request**

Run `vendor/bin/pest tests/Feature/Tooling`, then commit with "docs: cut the changelog for v1.0.0 and mark the release in the readme and the manual" and the Co-Authored-By line. Push, open the pull request, wait for all 11 checks, then merge (Task 7 Step 4).

### Task 14: **[APPROVAL] the tag `v1.0.0`** and the optional GitHub release (Consequence (12))

- [ ] **Step 1: Ask** ("[APPROVAL 7/7] Tag")

Ask: tag `main` at `<hash>` (the merged release pull request) as `v1.0.0`, annotated, and push the tag. Separately: publish a GitHub release with the CHANGELOG's 1.0.0 section as its notes, yes or no.

- [ ] **Step 2: Tag**

```bash
git switch main && git pull --ff-only
git tag -a v1.0.0 -m "skv3 v1.0.0"
git push origin v1.0.0
```

- [ ] **Step 3: The release, only with its own yes**

```bash
awk '/^## \[1\.0\.0\]/{found=1; next} /^## \[/{if(found) exit} found' CHANGELOG.md > "$SCRATCH"/release-notes.md
gh release create v1.0.0 --repo frankcornet/skv3 --title "v1.0.0" --notes-file "$SCRATCH"/release-notes.md --verify-tag
```

### Task 15: Release check after the tag, the addendum and the hand-off (Consequence (13); §12 (4))

- [ ] **Step 1: Release check after the tag**

`gh run list --repo frankcornet/skv3 --workflow release-check --limit 2`. The tag push and the release, if any, each start a run, and the later one cancels the earlier (concurrency). Watch the last one. Expected: green. Also check `curl -s https://repo.packagist.org/p2/frankcornet/skv3.json | jq -r '.packages["frankcornet/skv3"][].version'`, which lists `v1.0.0` (or `1.0.0`).

- [ ] **Step 2: Fill the addendum**

Replace DEC-36's six "(filled in after publication)" markers with:
- the approval dates;
- the run URLs and the fix-forward pull requests (number, cause, fix);
- the ruleset id;
- the Packagist reference check and the dist-install result;
- the two Herd GUI durations (with CD-9's note on configuration B);
- the release-check runs before and after the tag.

Commit on a branch, open a pull request and merge it (it is docs only, covered by CD-6).

- [ ] **Step 3: Memory and hand-off**

Update the memory notes: M8b done, the repository URL, the ruleset, and that `main` takes only pull requests. Report to the owner. Leftovers that are theirs: the Herd Pro mailboxes of the throwaway sites, and the stale plugin-registry entries.
