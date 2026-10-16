<?php

declare(strict_types=1);

/** covers §8 */

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

it('keeps CI in tests.yml with matrix PHP versions only', function (): void {
    $ci = file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($ci)->toContain('php-version: ${{ matrix.php }}')
        ->and(preg_match("/php-version:\s*'\d+\.\d+'/", (string) $ci))->toBe(0)
        ->and($ci)->not->toContain('./vendor/bin/phpunit')
        ->and($ci)->toContain('postgres:18')
        ->and($ci)->toContain('composer check')
        ->and(file_exists(base_path('.github/workflows/ci.yml')))->toBeFalse();
});

/**
 * The committed version of $file in $repository (§6.7): a CI job that ran skv:flux-pro has flux-pro in its working
 * tree. Without a repository, or before its first commit (GitStep leaves `git init` without one), the working-tree
 * file is what will be committed.
 */
$committedFile = function (string $repository, string $file): string {
    $hasCommit = file_exists($repository.'/.git')
        && Process::path($repository)->run(['git', 'rev-parse', '--verify', '--quiet', 'HEAD'])->successful();

    return $hasCommit
        ? Process::path($repository)->run(['git', 'show', 'HEAD:'.$file])->throw()->output()
        : (string) file_get_contents($repository.'/'.$file);
};

/** Whether a composer.json carries skv:flux-pro's mark (CD-13): an app that commits Flux Pro on purpose carries it. */
$commitsFluxPro = function (string $composerJson): bool {
    $composer = json_decode($composerJson, true);

    return is_array($composer) && data_get($composer, 'extra.skv.flux-pro') === true;
};

/**
 * git in a throwaway repository, without the developer's global or system configuration (M33): a core.fsmonitor
 * there would start a daemon, and an init.templateDir would copy its hooks into the repository.
 */
$sandboxGit = fn (string $repository): PendingProcess => Process::path($repository)->env(['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);

/** A composer.json whose mark is $flag (data_set keeps the kit's namespace in one allowed form, NameLeakTest). */
$withFluxProMark = function (mixed $flag): string {
    $composer = ['name' => 'acme/app', 'require' => ['livewire/flux-pro' => '^2.0']];
    data_set($composer, 'extra.skv.flux-pro', $flag);

    return json_encode($composer, JSON_THROW_ON_ERROR);
};

it('never references Flux Pro in the committed composer files (committed)', function () use ($committedFile): void {
    expect($committedFile(base_path(), 'composer.json'))->toContain('"name"')->not->toContain('flux-pro')->not->toContain('composer.fluxui.dev')
        ->and($committedFile(base_path(), 'composer.lock'))->toContain('"packages"')->not->toContain('flux-pro')->not->toContain('composer.fluxui.dev');
})->skip(fn (): bool => $commitsFluxPro($committedFile(base_path(), 'composer.json')), 'this app committed Flux Pro on purpose with skv:flux-pro');

it('skips the committed-files check only for a commit that skv:flux-pro marked (CD-13)', function () use ($commitsFluxPro, $committedFile, $withFluxProMark, $sandboxGit): void {
    $repository = storage_path('framework/testing/ci-flux-pro-'.Str::uuid());
    File::ensureDirectoryExists($repository);
    File::put($repository.'/composer.json', $withFluxProMark(true));

    try {
        $sandboxGit($repository)->run(['git', 'init', '--quiet'])->throw();
        $sandboxGit($repository)->run(['git', 'add', 'composer.json'])->throw();
        $sandboxGit($repository)->run(['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.com', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', 'Add Flux Pro'])->throw();

        expect($commitsFluxPro($committedFile($repository, 'composer.json')))->toBeTrue()
            ->and($commitsFluxPro($withFluxProMark('true')))->toBeFalse()
            ->and($commitsFluxPro((string) json_encode(['require' => ['livewire/flux-pro' => '^2.0']])))->toBeFalse()
            ->and($commitsFluxPro('{'))->toBeFalse();
    } finally {
        File::deleteDirectory($repository);
    }
});

it('reads the working-tree composer files in a repository without a commit yet (§6.7)', function () use ($committedFile, $sandboxGit): void {
    $repository = storage_path('framework/testing/ci-unborn-'.Str::uuid());
    File::ensureDirectoryExists($repository);
    File::put($repository.'/composer.json', '{"name": "acme/app"}');

    try {
        $sandboxGit($repository)->run(['git', 'init', '--quiet'])->throw();

        expect($committedFile($repository, 'composer.json'))->toBe('{"name": "acme/app"}');
    } finally {
        File::deleteDirectory($repository);
    }
});

it('reports abandoned packages in composer audit instead of failing', function (): void {
    /** @var array{config: array{audit?: array{abandoned?: string}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['config']['audit']['abandoned'] ?? null)->toBe('report');
});

it('caches the Composer cache dir reported by Composer itself', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($ci)->toContain('composer config cache-files-dir')
        ->and($ci)->toContain('${{ steps.composer-cache.outputs.dir }}')
        ->and($ci)->not->toContain('~/.composer/cache');
});

it('keeps Dependabot on actions, composer and npm', function (): void {
    $path = base_path('.github/dependabot.yml');

    expect(is_file($path))->toBeTrue();

    $dependabot = (string) file_get_contents($path);

    foreach (['github-actions', 'composer', 'npm'] as $ecosystem) {
        expect($dependabot)->toContain(sprintf('package-ecosystem: "%s"', $ecosystem));
    }
});

it('runs the sqlite-free job with the impersonation module off (IMP-12)', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect(Str::between($ci, '- name: sqlite-free', '- name: php85'))->toContain("impersonation: 'false'")
        ->and(Str::between($ci, '- name: main', '- name: sqlite-free'))->toContain("impersonation: 'true'")
        ->and(Str::between($ci, '- name: php85', 'services:'))->toContain("impersonation: 'true'")
        ->and($ci)->toContain('MODULE_IMPERSONATION: ${{ matrix.impersonation }}');
});

it('runs the free job with the passkeys module off (§6.8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)->toContain('MODULE_PASSKEYS: ${{ matrix.passkeys }}')
        ->and(preg_match("/name: sqlite-free.*?passkeys: 'false'/s", $workflow))->toBe(1);
});

it('lets the environment turn the module off in tests and pins the test hosts (IMP-12, FU2-9, FU3-17)', function (): void {
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)
        ->toContain('<env name="MODULE_IMPERSONATION" value="true"/>')
        ->toContain('<env name="APP_URL" value="http://localhost" force="true"/>')
        ->toContain('<env name="TENANCY_APEX_DOMAIN" value="localhost" force="true"/>')
        ->toContain('<env name="TENANCY_ADMIN_SUBDOMAIN" value="admin" force="true"/>')
        ->toContain('<env name="TENANCY_STRICT" value="true" force="true"/>')
        ->toContain('<env name="PASSKEYS_RELYING_PARTY_ID" value=""/>')
        ->toContain('<env name="TRUSTED_PROXIES" value=""/>');
});

it('runs the browser suite in its own CI job (§8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)->toContain('npx playwright install --with-deps chromium')
        ->toContain('composer test:browser')
        ->toContain('tests/Browser/Traces')
        ->and(preg_match('/uses: actions\/upload-artifact@[0-9a-f]{40}/', $workflow))->toBe(1);
});

it('caches Composer and the Playwright browsers in the browser job and keeps failure traces briefly (§8)', function (): void {
    $browser = Str::after((string) file_get_contents(base_path('.github/workflows/tests.yml')), "\n  browser:\n");

    expect($browser)->toContain('composer config cache-files-dir')
        ->toContain('${{ steps.composer-cache.outputs.dir }}')
        ->toContain('path: ~/.cache/ms-playwright')
        ->toContain("require('./package-lock.json').packages['node_modules/playwright'].version")
        ->toContain('key: ${{ runner.os }}-playwright-${{ steps.playwright.outputs.version }}')
        ->toContain("if: steps.playwright-cache.outputs.cache-hit != 'true'")
        ->toContain("if: steps.playwright-cache.outputs.cache-hit == 'true'")
        ->toContain('npx playwright install-deps chromium')
        ->toContain('retention-days: 7')
        ->and(preg_match_all('/uses: actions\/cache@[0-9a-f]{40}/', $browser))->toBe(2);
});

it('enables Flux Pro in CI only when the secrets exist, before any view compiles (§6.7, §8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    $check = Str::before(Str::after($workflow, "\n  check:\n"), "\n  browser:\n");
    $jobEnv = Str::before(Str::after($check, "\n    env:\n"), "\n    services:\n");
    $step = Str::before(Str::after($check, '- name: Enable Flux Pro'), '- name: ');

    expect($jobEnv)
        // The job maps only whether the secrets exist; the key itself never reaches install scripts or the test run.
        ->toContain('FLUX_PRO_SECRETS: ${{ secrets.FLUX_USERNAME != \'\' && secrets.FLUX_LICENSE_KEY != \'\' }}')
        ->not->toContain('secrets.FLUX_USERNAME }}')
        ->not->toContain('secrets.FLUX_LICENSE_KEY }}')
        ->and(substr_count($check, 'secrets.FLUX_LICENSE_KEY }}'))->toBe(1)
        // Workflow-wide: one Flux Pro step in check and one in e2e, so no workflow-level env or browser step maps them.
        ->and(substr_count($workflow, 'secrets.FLUX_LICENSE_KEY }}'))->toBe(2)
        ->and(substr_count($workflow, 'secrets.FLUX_USERNAME }}'))->toBe(2)
        ->and($step)
        ->toContain("if: env.FLUX_PRO_SECRETS == 'true' && matrix.db == 'pgsql'")
        ->toContain('FLUX_USERNAME: ${{ secrets.FLUX_USERNAME }}')
        ->toContain('FLUX_LICENSE_KEY: ${{ secrets.FLUX_LICENSE_KEY }}')
        // The Pro dist zip must never land in the cached Composer files directory, which pull requests can restore.
        ->toContain('COMPOSER_CACHE_DIR: ${{ runner.temp }}/composer-flux-pro')
        ->toContain('composer config --global http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"')
        ->toContain('php artisan skv:flux-pro --no-interaction')
        ->and(Str::before($workflow, 'composer check'))->toContain('skv:flux-pro');
});

it('runs the installer end to end in an e2e job (§8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    // Derived from composer.json: NameLeakTest scans tests/, so this file holds no kit name literal.
    $package = (string) json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['name'];

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('schedule:')
        ->toContain('  e2e:')
        ->toContain('fetch-depth: 0')
        ->toContain('git switch -C ci')
        ->toContain('composer config -g repositories.'.Str::after($package, '/').' vcs "$GITHUB_WORKSPACE"')
        ->toContain("--using={$package}:dev-ci")
        ->toContain('--no-interaction --no-ansi --database=sqlite --npm')
        ->toContain('skv:install --no-interaction --answers=')
        ->toContain('--answers=@"$RUNNER_TEMP/e2e-answers.json"')
        ->toContain('skv:doctor --json')
        ->toContain("flags: '--pest --git --boost'")
        ->toContain("flags: '--phpunit --git --no-boost'");
});

it('maps the Flux Pro credentials of the e2e job in its Flux Pro step alone (§6.7, §8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $e2e = Str::after($workflow, "\n  e2e:\n");
    $jobEnv = Str::before(Str::after($e2e, "\n    env:\n"), "\n    services:\n");
    $step = Str::before(Str::after($e2e, '- name: Enable Flux Pro'), '- name: ');

    expect($workflow)->toContain("\n  e2e:\n")
        ->and($jobEnv)
        ->toContain('FLUX_PRO_SECRETS: ${{ secrets.FLUX_USERNAME != \'\' && secrets.FLUX_LICENSE_KEY != \'\' }}')
        ->not->toContain('secrets.FLUX_LICENSE_KEY }}')
        ->and(substr_count($e2e, 'secrets.FLUX_LICENSE_KEY }}'))->toBe(1)
        ->and($step)
        ->toContain("env.FLUX_PRO_SECRETS == 'true'")
        ->toContain('FLUX_LICENSE_KEY: ${{ secrets.FLUX_LICENSE_KEY }}')
        ->toContain('php artisan skv:flux-pro --no-interaction');
});

it('ships a local e2e script that never touches global Composer config (OD-3)', function (): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));

    expect(is_executable(base_path('scripts/e2e-local.sh')))->toBeTrue()
        ->and($script)->toContain('COMPOSER_HOME=')
        ->and($script)->toContain('skv:install --no-interaction --answers=')
        ->and($script)->toContain('--answers=@"$ANSWERS"')
        ->and($script)->not->toContain('composer config --global --list');
});

it('quotes the names in the cleanup commands the local e2e script prints (RL-6, g2-05)', function (): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));
    $cleanup = Str::after($script, 'Clean up when you are done');

    expect($cleanup)->toContain("printf '  herd unsecure %q\\n' \"\$NAME\"")
        ->toContain("printf '  herd unisolate %q\\n' \"--site=\$NAME\"")
        ->toContain("printf '  herd unlink %q\\n' \"\$NAME\"")
        ->toContain("printf '  rm -rf %q\\n' \"\$TARGET\"")
        // An echo would print the name as it is, so a folder with a space or a quote gives a broken command.
        ->and(preg_match('/echo "  (herd|rm) /', $cleanup))->toBe(0);
});

it('checks in CI that the setup stylesheet is current, and names the dependencies that change it (OD-1, g4-07)', function (): void {
    expect((string) file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain('npm run build:setup && git diff --exit-code resources/setup/setup.css')
        // A Dependabot update of these changes the built stylesheet too, so the guideline names them.
        ->and((string) file_get_contents(base_path('.ai/guidelines/skv3.md')))
        ->toContain('`tailwindcss`')->toContain('`@tailwindcss/vite`')->toContain('`vite`')->toContain('`livewire/flux`');
});

it('checks the wizard after every entry point in the phase-1 job (§7.2, OD-3)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));

    expect($workflow)->toContain('  phase1:')
        ->toContain('entry: [terminal, terminal-nodb, composer, clone]')
        ->toContain('scripts/e2e-local.sh "$RUNNER_TEMP/phase1-app" --entry=${{ matrix.entry }}')
        ->and($script)->toContain('--entry=')
        ->toContain('--phase1-only')
        ->toContain('php artisan serve')
        ->toContain('/welcome-setup')
        ->toContain('SetupToken::PATH')
        ->not->toContain('composer config --global --list');
});

it('never hides a failed command substitution behind export or local in the shell scripts (g1-14)', function (): void {
    $scripts = [...(glob(base_path('scripts/*.sh')) ?: []), ...(glob(base_path('.githooks/*')) ?: [])];
    // `export X="$(cmd)"` returns export's status, so set -e never sees a failing cmd; assign first, then export.
    $masked = array_values(array_filter($scripts, fn (string $path): bool => preg_match('/^\s*(?:export|local|readonly|declare)\s+(?:-\w+\s+)*[A-Za-z_]\w*="?\$\(/m', (string) file_get_contents($path)) === 1));

    expect($scripts)->not->toBeEmpty()
        ->and($masked)->toBe([]);
});
