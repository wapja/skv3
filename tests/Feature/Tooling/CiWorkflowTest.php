<?php

declare(strict_types=1);

/** covers §8 */

use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\RequiredPostgres;

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
 * tests.yml, parsed (F35): the workflow's structure is checked on what GitHub reads, not on its text. Symfony's parser
 * keeps `on` a string key, as GitHub does; a file it cannot parse fails every test that reads it.
 *
 * @return array<string, mixed>
 */
$workflow = function (): array {
    $parsed = Yaml::parseFile(base_path('.github/workflows/tests.yml'));

    expect($parsed)->toBeArray();

    /** @var array<string, mixed> $parsed */
    return $parsed;
};

/**
 * The steps of $job, in order.
 *
 * @return list<array<string, mixed>>
 */
$steps = function (string $job) use ($workflow): array {
    $steps = $workflow()['jobs'][$job]['steps'] ?? null;

    expect($steps)->toBeArray()->not->toBeEmpty();

    /** @var list<array<string, mixed>> $steps */
    return $steps;
};

/**
 * The one step of $job named $name.
 *
 * @return array<string, mixed>
 */
$step = function (string $job, string $name) use ($steps): array {
    $found = array_values(array_filter($steps($job), fn (array $step): bool => ($step['name'] ?? null) === $name));

    expect($found)->toHaveCount(1, "{$job} has no single step named [{$name}]");

    return $found[0];
};

/** Where the step named $name sits in $job (a missing step sorts last). */
$position = function (string $job, string $name) use ($steps): int {
    $index = array_search($name, array_column($steps($job), 'name'), true);

    return is_int($index) ? $index : PHP_INT_MAX;
};

/**
 * The environment of every git process this file starts (M33): no global or system configuration (a core.fsmonitor
 * there would start a daemon, an init.templateDir would copy its hooks), and none of git's repository variables. Git
 * exports GIT_DIR to its hooks, as an absolute path in a linked worktree, so a suite run from a hook would otherwise
 * commit the fixtures onto the developer's branch. false unsets a variable (Symfony Process). The names are
 * `git rev-parse --local-env-vars` plus GIT_NAMESPACE.
 *
 * @var array<string, string|false> $gitEnvironment
 */
$gitEnvironment = [
    ...array_fill_keys([
        'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_CONFIG', 'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG_COUNT', 'GIT_OBJECT_DIRECTORY',
        'GIT_DIR', 'GIT_WORK_TREE', 'GIT_IMPLICIT_WORK_TREE', 'GIT_GRAFT_FILE', 'GIT_INDEX_FILE', 'GIT_NO_REPLACE_OBJECTS',
        'GIT_REPLACE_REF_BASE', 'GIT_PREFIX', 'GIT_SHALLOW_FILE', 'GIT_COMMON_DIR', 'GIT_NAMESPACE',
    ], false),
    'GIT_CONFIG_GLOBAL' => '/dev/null',
    'GIT_CONFIG_NOSYSTEM' => '1',
];

/** $script run as GitHub runs a `shell: bash` step (`bash --noprofile --norc -eo pipefail {0}`), in $path. */
$bash = fn (string $script, string $path, array $env = []): ProcessResult => Process::path($path)
    ->env([...$env, ...$gitEnvironment])
    ->run(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $script]);

/** A step's script with every line trimmed, so a block nested in an `if` compares like a top-level one. */
$trimmed = fn (mixed $script): string => implode("\n", array_map(trim(...), explode("\n", is_string($script) ? $script : '')));

/** The lines each step that needs the Flux credentials runs to hand them to Composer (CD-9), trimmed. */
$composerAuth = <<<'SH'
    COMPOSER_AUTH="$(php -r 'echo json_encode(["http-basic" => ["composer.fluxui.dev" => ["username" => getenv("FLUX_USERNAME"), "password" => getenv("FLUX_LICENSE_KEY")]]], JSON_THROW_ON_ERROR);')"
    export COMPOSER_AUTH
    SH;

/** The kit's package name, read from composer.json: NameLeakTest scans tests/, so this file holds no kit name literal. */
$package = fn (): string => (string) json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['name'];

/**
 * The committed version of $file in $repository (§6.7): a CI job that ran skv:flux-pro has flux-pro in its working
 * tree. Without a repository, or before its first commit (GitStep leaves `git init` without one), the working-tree
 * file is what will be committed.
 */
$committedFile = function (string $repository, string $file) use ($gitEnvironment): string {
    $hasCommit = file_exists($repository.'/.git')
        && Process::path($repository)->env($gitEnvironment)->run(['git', 'rev-parse', '--verify', '--quiet', 'HEAD'])->successful();

    return $hasCommit
        ? Process::path($repository)->env($gitEnvironment)->run(['git', 'show', 'HEAD:'.$file])->throw()->output()
        : (string) file_get_contents($repository.'/'.$file);
};

/** Whether a composer.json carries skv:flux-pro's mark (CD-13): an app that commits Flux Pro on purpose carries it. */
$commitsFluxPro = function (string $composerJson): bool {
    $composer = json_decode($composerJson, true);

    return is_array($composer) && data_get($composer, 'extra.skv.flux-pro') === true;
};

/** git in a throwaway repository, in the isolated $gitEnvironment. */
$sandboxGit = fn (string $repository): PendingProcess => Process::path($repository)->env($gitEnvironment);

/** A composer.json whose mark is $flag (data_set keeps the kit's namespace in one allowed form, NameLeakTest). */
$withFluxProMark = function (mixed $flag): string {
    $composer = ['name' => 'acme/app', 'require' => ['livewire/flux-pro' => '^2.0']];
    data_set($composer, 'extra.skv.flux-pro', $flag);

    return json_encode($composer, JSON_THROW_ON_ERROR);
};

it('keeps every git process of this file out of a repository the environment names (GIT_DIR from a hook)', function (array $names) use ($gitEnvironment, $sandboxGit, $bash, $committedFile): void {
    // A newer git may add a repository variable: the list must still unset every one this git knows.
    $local = preg_split('/\s+/', trim($sandboxGit(base_path())->run(['git', 'rev-parse', '--local-env-vars'])->throw()->output())) ?: [];

    expect($local)->toContain('GIT_DIR')
        ->and(array_values(array_diff($local, array_keys(array_filter($gitEnvironment, fn (string|false $value): bool => $value === false)))))->toBe([]);

    $root = storage_path('framework/testing/ci-git-env-'.Str::uuid());
    $decoy = $root.'/decoy';
    $repository = $root.'/sandbox';
    $commit = ['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.com', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m'];
    $decoyHead = fn (): string => trim($sandboxGit($decoy)->run(['git', 'log', '--format=%H %s'])->throw()->output());
    File::ensureDirectoryExists($decoy);
    File::ensureDirectoryExists($repository);
    File::put($decoy.'/README', 'decoy');
    File::put($repository.'/composer.json', '{"name": "acme/app"}');

    try {
        $sandboxGit($decoy)->run(['git', 'init', '--quiet'])->throw();
        $sandboxGit($decoy)->run(['git', 'add', 'README'])->throw();
        $sandboxGit($decoy)->run([...$commit, 'Decoy'])->throw();
        $before = $decoyHead();

        $exported = array_intersect_key(['GIT_DIR' => $decoy.'/.git', 'GIT_WORK_TREE' => $decoy, 'GIT_INDEX_FILE' => $decoy.'/.git/index'], array_flip($names));
        $saved = [];

        foreach ($exported as $name => $value) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            $sandboxGit($repository)->run(['git', 'init', '--quiet'])->throw();
            $sandboxGit($repository)->run(['git', 'add', 'composer.json'])->throw();
            $sandboxGit($repository)->run([...$commit, 'Sandbox'])->throw();
            $bash("git -c user.name=CI -c user.email=ci@example.com -c commit.gpgsign=false -c core.hooksPath=/dev/null commit --quiet --allow-empty -m Bash\n", $repository)->throw();
            $committed = $committedFile($repository, 'composer.json');
        } finally {
            foreach ($saved as $name => [$value, $env, $server]) {
                putenv($value === false ? $name : "{$name}={$value}");

                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }

                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }

        expect($decoyHead())->toBe($before)
            ->and(str_ends_with($before, ' Decoy') && substr_count($before, "\n") === 0)->toBeTrue()
            ->and(trim($sandboxGit($repository)->run(['git', 'log', '--format=%s'])->throw()->output()))->toBe("Bash\nSandbox")
            ->and($committed)->toBe('{"name": "acme/app"}');
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    // Git exports GIT_DIR to every hook, as an absolute path in a linked worktree, and GIT_INDEX_FILE to pre-commit.
    'a pre-push hook' => [['GIT_DIR']],
    'a pre-commit hook' => [['GIT_DIR', 'GIT_INDEX_FILE']],
    'an exported work tree' => [['GIT_DIR', 'GIT_WORK_TREE']],
]);

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

it('refuses a committed Flux Pro in the kit\'s own repository only (CD-11, M7b M1)', function (string $composerJson, string $composerLock, ?string $workingTree, bool $passes) use ($step, $position, $bash, $package, $sandboxGit): void {
    $guard = $step('check', 'Kit commits no Flux Pro');
    $repository = storage_path('framework/testing/ci-kit-guard-'.Str::uuid());
    File::ensureDirectoryExists($repository);
    File::put($repository.'/composer.json', $composerJson);
    File::put($repository.'/composer.lock', $composerLock);

    try {
        $sandboxGit($repository)->run(['git', 'init', '--quiet'])->throw();
        $sandboxGit($repository)->run(['git', 'add', 'composer.json', 'composer.lock'])->throw();
        $sandboxGit($repository)->run(['git', '-c', 'user.name=CI', '-c', 'user.email=ci@example.com', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', 'Composer files'])->throw();

        if ($workingTree !== null) {
            // What the Flux Pro step leaves in the working tree never counts: the guard reads the commit.
            File::put($repository.'/composer.json', $workingTree);
        }

        $result = $bash((string) $guard['run'], $repository);

        // Generated apps may commit the mark on purpose (CD-13 of M7b): the step runs in the kit's repository alone.
        expect($guard['if'])->toBe("github.repository == '{$package()}'")
            ->and($position('check', 'Kit commits no Flux Pro'))->toBeGreaterThan($position('check', 'Setup PHP'))
            ->and($result->successful())->toBe($passes, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($repository);
    }
})->with(function () use ($withFluxProMark): array {
    $clean = (string) json_encode(['name' => 'acme/app', 'require' => ['livewire/flux' => '^2.13']]);
    $cleanLock = (string) json_encode(['packages' => [['name' => 'livewire/flux']]]);
    $otherMark = ['name' => 'acme/app'];
    data_set($otherMark, 'extra.skv', ['note' => true]);

    return [
        'clean files' => [$clean, $cleanLock, null, true],
        'a clean commit under a working tree with Flux Pro' => [$clean, $cleanLock, $withFluxProMark(true), true],
        'the mark and flux-pro in composer.json' => [$withFluxProMark(true), $cleanLock, null, false],
        'flux-pro in the lock only' => [$clean, (string) json_encode(['packages' => [['name' => 'livewire/flux-pro']]]), null, false],
        'the fluxui repository' => [(string) json_encode(['name' => 'acme/app', 'repositories' => ['pro' => ['type' => 'composer', 'url' => 'https://composer.fluxui.dev']]]), $cleanLock, null, false],
        'an extra namespace of the kit without flux-pro' => [(string) json_encode($otherMark), $cleanLock, null, false],
    ];
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

it('pins every action by commit, keeps checkout credentials out of the workspace and bounds every job (§8 Hardening, F35)', function () use ($workflow): void {
    $text = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $parsed = $workflow();
    preg_match_all('/^\s*(?:- )?uses: (.+)$/m', $text, $uses);
    $checkouts = 0;

    expect($uses[1])->not->toBeEmpty()
        // A tag or a branch can be moved to other code; a full commit SHA cannot. The comment names the release.
        ->each->toMatch('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40} # v?\d+(\.\d+)*$/')
        ->and($parsed['permissions'])->toBe(['contents' => 'read'])
        ->and($parsed['concurrency']['cancel-in-progress'] ?? null)->toBeTrue()
        ->and($parsed['jobs'])->toBeArray()->toHaveKeys(['check', 'browser', 'phase1', 'e2e']);

    foreach ($parsed['jobs'] as $id => $job) {
        expect($job['timeout-minutes'] ?? null)->toBeInt("{$id} has no timeout-minutes")->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
            ->and($job)->not->toHaveKey('permissions');

        foreach ($job['steps'] as $jobStep) {
            if (str_starts_with((string) ($jobStep['uses'] ?? ''), 'actions/checkout@')) {
                $checkouts++;

                expect($jobStep['with']['persist-credentials'] ?? null)->toBeFalse("a checkout in {$id} keeps the token in .git/config");
            }
        }
    }

    expect($checkouts)->toBe(count($parsed['jobs']));
});

it('runs the sqlite-free job with the impersonation module off (IMP-12)', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect(Str::between($ci, '- name: sqlite-free', '- name: php85'))->toContain("impersonation: 'false'")
        ->and(Str::between($ci, '- name: main', '- name: sqlite-free'))->toContain("impersonation: 'true'")
        ->and(Str::between($ci, '- name: php85', 'services:'))->toContain("impersonation: 'true'")
        ->and($ci)->toContain('MODULE_IMPERSONATION: ${{ matrix.impersonation }}');
});

it('runs the free job with the passkeys module off and every other row with it on (§6.8, A80)', function () use ($workflow): void {
    $parsed = $workflow();
    $modules = [];

    foreach ($parsed['jobs']['check']['strategy']['matrix']['include'] as $row) {
        $modules[Str::before((string) $row['name'], ' ')] = ['passkeys' => $row['passkeys'], 'impersonation' => $row['impersonation']];
    }

    expect($parsed['jobs']['check']['env']['MODULE_PASSKEYS'])->toBe('${{ matrix.passkeys }}')
        ->and($modules)->toBe([
            'main' => ['passkeys' => 'true', 'impersonation' => 'true'],
            'sqlite-free' => ['passkeys' => 'false', 'impersonation' => 'false'],
            'php85' => ['passkeys' => 'true', 'impersonation' => 'true'],
        ]);
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

it('runs the PostgreSQL tests in CI as Herd\'s root user and fails instead of skipping them (CD-10, F16, g3-39)', function () use ($workflow, $step): void {
    $jobs = $workflow()['jobs'];

    // Detector::postgresReachable() and the wizard expect Herd's credentials: user root without a password (RL-4).
    expect($jobs['check']['services']['postgres']['image'])->toBe('postgres:18')
        ->and($jobs['check']['services']['postgres']['env'])->toBe(['POSTGRES_USER' => 'root', 'POSTGRES_HOST_AUTH_METHOD' => 'trust', 'POSTGRES_DB' => 'app_test'])
        ->and($jobs['check']['services']['postgres']['options'])->toContain('pg_isready -U root')
        ->and($step('check', 'Check (PostgreSQL)')['env'])->toBe([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 5432,
            'DB_DATABASE' => 'app_test',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            RequiredPostgres::FLAG => 'true',
        ])
        ->and($jobs['browser']['services']['postgres']['image'])->toBe('postgres:18')
        ->and($jobs['browser']['services']['postgres']['env'])->toBe(['POSTGRES_USER' => 'root', 'POSTGRES_HOST_AUTH_METHOD' => 'trust'])
        ->and($jobs['browser']['services']['postgres']['options'])->toContain('pg_isready -U root')
        ->and($step('browser', 'Setup PHP')['with']['extensions'])->toContain('pdo_pgsql')
        ->and($step('browser', 'Browser tests')['env'] ?? null)->toBe([RequiredPostgres::FLAG => 'true'])
        // The SQLite rows have no server to require.
        ->and($step('check', 'Check (SQLite)'))->not->toHaveKey('env')
        ->and($jobs['check']['env'])->not->toHaveKey(RequiredPostgres::FLAG);
});

it('installs a PostgreSQL 18 pg_dump where the toolchain looks first, before the PostgreSQL check (L-06, F17)', function () use ($step, $position): void {
    $client = $step('check', 'PostgreSQL 18 client');
    $path = explode(':', new Toolchain(storage_path('framework/testing/home'))->environment()['PATH']);
    $local = array_search('/usr/local/bin', $path, true);
    $system = array_search('/usr/bin', $path, true);

    expect($client['if'])->toBe("matrix.db == 'pgsql'")
        ->and($client['run'])->toContain('sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y')
        ->toContain('postgresql-client-18')
        ->toContain('sudo ln -sf /usr/lib/postgresql/18/bin/pg_dump /usr/local/bin/pg_dump')
        ->toContain("/usr/local/bin/pg_dump --version | grep -q '(PostgreSQL) 18\\.'")
        ->and($position('check', 'PostgreSQL 18 client'))->toBeLessThan($position('check', 'Check (PostgreSQL)'))
        // BackupRunTest finds pg_dump on this PATH: /usr/local/bin comes before the runner's /usr/bin/pg_dump wrapper.
        ->and($local)->toBeInt()
        ->and($system)->toBeInt()
        ->and(is_int($local) && is_int($system) && $local < $system)->toBeTrue();
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

it('enables Flux Pro in CI only when the secrets exist, after the audit and before any view compiles (§6.7, §8, CD-9)', function () use ($workflow, $step, $position, $trimmed, $composerAuth): void {
    $pro = $step('check', 'Enable Flux Pro');

    // The job maps only whether the secrets exist; the key itself never reaches install scripts or the test run.
    expect($workflow()['jobs']['check']['env']['FLUX_PRO_SECRETS'])->toBe("\${{ secrets.FLUX_USERNAME != '' && secrets.FLUX_LICENSE_KEY != '' }}")
        ->and($pro['if'])->toBe("env.FLUX_PRO_SECRETS == 'true' && matrix.db == 'pgsql'")
        ->and($pro['env'])->toBe([
            'FLUX_USERNAME' => '${{ secrets.FLUX_USERNAME }}',
            'FLUX_LICENSE_KEY' => '${{ secrets.FLUX_LICENSE_KEY }}',
            // The Pro dist zip must never land in the cached Composer files directory, which pull requests can restore.
            'COMPOSER_CACHE_DIR' => '${{ runner.temp }}/composer-flux-pro',
        ])
        ->and($trimmed($pro['run']))->toBe($composerAuth."\nphp artisan skv:flux-pro --no-interaction\n")
        // The audit reads the committed lock, which never holds flux-pro, so it runs before Composer gets the key.
        ->and($position('check', 'Audit dependencies'))->toBeLessThan($position('check', 'Enable Flux Pro'))
        ->and($position('check', 'Enable Flux Pro'))->toBeLessThan($position('check', 'Check (SQLite)'))
        ->and($position('check', 'Enable Flux Pro'))->toBeLessThan($position('check', 'Check (PostgreSQL)'));
});

it('hands the Flux credentials to Composer only in the steps that need them, never through a global auth.json (CD-9, g2-01)', function () use ($workflow, $step, $trimmed, $composerAuth): void {
    $text = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $secretSteps = [];
    $secretJobEnv = [];

    foreach ($workflow()['jobs'] as $id => $job) {
        foreach ($job['env'] ?? [] as $key => $value) {
            if (str_contains((string) $value, 'secrets.')) {
                $secretJobEnv[] = "{$id}: {$key}";
            }
        }

        foreach ($job['steps'] as $jobStep) {
            foreach ($jobStep['env'] ?? [] as $key => $value) {
                if (str_contains((string) $value, 'secrets.')) {
                    $secretSteps["{$id}: {$jobStep['name']}"][] = $key;
                }
            }

            expect((string) ($jobStep['run'] ?? ''))->not->toContain('secrets.');
        }
    }

    expect($secretJobEnv)->toBe(['check: FLUX_PRO_SECRETS', 'e2e: FLUX_PRO_SECRETS'])
        ->and($secretSteps)->toBe([
            'check: Enable Flux Pro' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            'e2e: Enable Flux Pro' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            'e2e: skv:install' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            'e2e: skv:doctor' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
        ])
        // B33: `composer config --global` wrote the key into COMPOSER_HOME/auth.json for every later step to read.
        ->and(preg_match('/composer config (?:-g|--global)\b[^\n]*http-basic/', $text))->toBe(0);

    foreach (array_keys($secretSteps) as $key) {
        [$job, $name] = explode(': ', $key, 2);

        expect($trimmed($step($job, $name)['run']))->toContain($composerAuth);
    }
});

it('builds COMPOSER_AUTH from the step\'s environment, so the licence key never reaches a command line (CD-9)', function () use ($bash, $composerAuth): void {
    $result = $bash($composerAuth."\nprintenv COMPOSER_AUTH", base_path(), ['FLUX_USERNAME' => 'dev@example.com', 'FLUX_LICENSE_KEY' => 'k"e\\y-1']);

    expect(json_decode($result->throw()->output(), true))->toBe(['http-basic' => ['composer.fluxui.dev' => ['username' => 'dev@example.com', 'password' => 'k"e\\y-1']]])
        // PHP reads the variables itself: the shell never expands the key into php's arguments.
        ->and($composerAuth)->not->toContain('$FLUX_');
});

it('runs the installer end to end in an e2e job (§8)', function () use ($package): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('schedule:')
        ->toContain('  e2e:')
        ->toContain('fetch-depth: 0')
        ->toContain('git switch -C ci')
        ->toContain('composer config -g repositories.'.Str::after($package(), '/').' vcs "$GITHUB_WORKSPACE"')
        ->toContain("--using={$package()}:dev-ci")
        ->toContain('--no-interaction --no-ansi --database=sqlite --npm')
        ->toContain('skv:install --no-interaction --answers=')
        ->toContain('--answers=@"$RUNNER_TEMP/e2e-answers.json"')
        ->toContain('skv:doctor --json')
        ->toContain("flags: '--pest --git --boost'")
        ->toContain("flags: '--phpunit --git --no-boost'");
});

it('maps the e2e job\'s Flux credentials only on the postgres-pro row (§6.7, §8, CD-9)', function () use ($workflow, $step, $position): void {
    $pro = $step('e2e', 'Enable Flux Pro');

    expect($workflow()['jobs']['e2e']['env']['FLUX_PRO_SECRETS'])->toBe("\${{ secrets.FLUX_USERNAME != '' && secrets.FLUX_LICENSE_KEY != '' }}")
        ->and($pro['if'])->toBe("steps.changes.outputs.run == 'true' && env.FLUX_PRO_SECRETS == 'true' && matrix.name == 'postgres-pro'")
        ->and($pro['env']['FLUX_LICENSE_KEY'])->toBe('${{ secrets.FLUX_LICENSE_KEY }}')
        ->and($pro['run'])->toContain('php artisan skv:flux-pro --no-interaction')
        ->and($position('e2e', 'Enable Flux Pro'))->toBeLessThan($position('e2e', 'skv:install'));

    // skv:install's composer require and skv:doctor's credentials check talk to the fluxui repository; the default
    // rows prove the credential-free path, so they get empty values (§8), and so does a run without both secrets,
    // where Enable Flux Pro never ran and nothing needs the key (plan review K8).
    foreach (['skv:install', 'skv:doctor'] as $name) {
        $credentialed = $step('e2e', $name);

        expect($credentialed['env'] ?? null)->toBe([
            'FLUX_USERNAME' => "\${{ matrix.name == 'postgres-pro' && env.FLUX_PRO_SECRETS == 'true' && secrets.FLUX_USERNAME || '' }}",
            'FLUX_LICENSE_KEY' => "\${{ matrix.name == 'postgres-pro' && env.FLUX_PRO_SECRETS == 'true' && secrets.FLUX_LICENSE_KEY || '' }}",
        ])->and($credentialed['run'])->toStartWith('if [ -n "$FLUX_LICENSE_KEY" ]; then');
    }
});

it('checks tests/ and tests/Pest.php after laravel new on every e2e row (g1-24, F23)', function () use ($step): void {
    $assertions = $step('e2e', 'Phase 1 assertions');

    // DEC-22: --phpunit leaves tests/ and the workflows exactly as shipped, so the phpunit-no-boost row checks them too.
    expect($assertions['run'])->toContain("\ndiff \"\$GITHUB_WORKSPACE/tests/Pest.php\" tmp/tests/Pest.php ")
        ->toContain("\ndiff -r \"\$GITHUB_WORKSPACE/tests\" tmp/tests ")
        ->not->toContain('FLAGS')
        ->and($assertions)->not->toHaveKey('env');
});

it('counts boost:update again after skv:install: exactly one with Boost, at most one without (F23)', function (string $flags, int $lines, bool $passes) use ($step, $position, $bash): void {
    $count = $step('e2e', 'boost:update after skv:install');
    $app = storage_path('framework/testing/ci-boost-'.Str::uuid());
    File::ensureDirectoryExists($app);
    File::put($app.'/composer.json', json_encode(['scripts' => ['post-update-cmd' => [
        '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
        ...array_fill(0, $lines, '@php artisan boost:update --ansi'),
    ]]], JSON_THROW_ON_ERROR));

    try {
        $result = $bash((string) $count['run'], $app, ['FLAGS' => $flags]);

        expect($count['env'])->toBe(['FLAGS' => '${{ matrix.flags }}'])
            ->and($position('e2e', 'skv:install'))->toBeLessThan($position('e2e', 'boost:update after skv:install'))
            ->and($position('e2e', 'boost:update after skv:install'))->toBeLessThan($position('e2e', 'composer check'))
            ->and($result->successful())->toBe($passes, $result->output().$result->errorOutput());
    } finally {
        File::deleteDirectory($app);
    }
})->with([
    'Boost, none' => ['--pest --git --boost', 0, false],
    'Boost, one' => ['--pest --git --boost', 1, true],
    'Boost, two' => ['--pest --git --boost', 2, false],
    'no Boost, none' => ['--phpunit --git --no-boost', 0, true],
    'no Boost, one' => ['--phpunit --git --no-boost', 1, true],
    'no Boost, two' => ['--phpunit --git --no-boost', 2, false],
]);

it('runs phase1 and e2e on pull requests that touch what they install (DEC-31, F25)', function () use ($step): void {
    $filters = array_map(function (string $job) use ($step): string {
        preg_match("/grep -qE '([^']+)' <<< \"\\\$changed\"/", (string) $step($job, 'Changes')['run'], $match);

        return $match[1] ?? '';
    }, ['phase1', 'e2e']);
    // grep -E and PCRE read this alternation of anchored paths alike.
    $touches = fn (string $path): bool => preg_match('#'.$filters[0].'#', $path) === 1;
    $first = fn (string $directory): string => Str::after(collect(File::allFiles(base_path($directory)))->first()?->getPathname() ?? '', base_path().'/');
    $installed = [
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.env.example',
        'app/Setup/SetupRunner.php', 'app/Console/Commands/InstallCommand.php', 'bootstrap/app.php', 'config/app.php',
        $first('database/migrations'), $first('database/seeders'), 'routes/setup.php', 'resources/setup/setup.css',
        $first('resources/views/pages/setup'), 'resources/views/layouts/setup.blade.php', $first('resources/views/setup'),
        'scripts/e2e-local.sh', 'tests/Fixtures/e2e-answers.json', '.github/workflows/tests.yml',
    ];
    $unrelated = [
        'app/Models/User.php', 'README.md', 'docs/decisions.md', 'lang/nl.json', 'routes/web.php',
        'resources/views/layouts/app.blade.php', 'tests/Feature/Tooling/CiWorkflowTest.php', '.github/dependabot.yml',
        'composer.json.orig', 'scripts/e2e-local.sh.bak',
    ];

    expect($filters[0])->not->toBe('')
        ->and($filters[1])->toBe($filters[0])
        ->and(array_filter($installed, fn (string $path): bool => ! is_file(base_path($path))))->toBe([])
        ->and(array_values(array_filter($installed, fn (string $path): bool => ! $touches($path))))->toBe([])
        ->and(array_values(array_filter($unrelated, $touches)))->toBe([]);
});

it('ships a local e2e script that never touches global Composer config (OD-3)', function (): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));

    expect(is_executable(base_path('scripts/e2e-local.sh')))->toBeTrue()
        ->and($script)->toContain('COMPOSER_HOME=')
        ->and($script)->toContain('skv:install --no-interaction --answers=')
        ->and($script)->toContain('--answers=@"$ANSWERS"')
        ->and($script)->not->toContain('composer config --global --list');
});

it('installs the e2e job\'s variants with the local script, and refuses any other (F56)', function () use ($workflow, $step): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));
    // Every refusal names an existing directory: should a check ever be skipped, the script stops at "it already
    // exists" instead of installing anything.
    $existing = storage_path('framework/testing/e2e-local-'.Str::uuid());
    File::ensureDirectoryExists($existing);
    $refusals = [
        [['--flags=--pest --bogus'], 'Unknown installer flag: --bogus'],
        [['--flags=--pest --pest'], 'Installer flag given twice: --pest'],
        [['--flags=--pest --phpunit'], 'Pick one of --pest and --phpunit'],
        [['--flags=--boost --no-boost'], 'Pick one of --pest and --phpunit'],
        [['--flags='], '--flags needs at least one installer flag.'],
        [['--db=mysql'], 'Unknown database: mysql'],
        [['--entry=terminal', '--flags=--pest'], '--flags and --db apply to the herd entry only.'],
        [['--entry=composer', '--db=pgsql'], '--flags and --db apply to the herd entry only.'],
    ];

    try {
        foreach ($refusals as [$arguments, $message]) {
            $result = Process::run(['bash', base_path('scripts/e2e-local.sh'), $existing, ...$arguments]);

            expect($result->exitCode())->toBe(2, implode(' ', $arguments).': '.$result->errorOutput())
                ->and($result->errorOutput())->toContain($message);
        }
    } finally {
        File::deleteDirectory($existing);
    }

    $rows = array_column($workflow()['jobs']['e2e']['strategy']['matrix']['include'], 'flags', 'name');
    preg_match('/\.modules=(\[[^\]]*\])/', (string) $step('e2e', 'Answers')['run'], $ciModules);
    preg_match('/\$a\["modules"\] = (\[[^\]]*\]);/', $script, $localModules);

    // The default is Herd's flag set, the e2e job's default row; --db=pgsql answers as the postgres-pro row does.
    expect($script)->toContain('FLAGS="'.$rows['default'].'"')
        ->toContain('--database=sqlite --npm "${INSTALLER_FLAGS[@]}"')
        ->toContain('if ($argv[4] === "pgsql") { $a["database"] = "pgsql";')
        ->toContain('check "post-update-cmd holds exactly one boost:update after skv:install" boost_updates_after_install')
        ->and($rows['phpunit-no-boost'])->toBe('--phpunit --git --no-boost')
        ->and(json_decode($localModules[1] ?? 'null', true))->toBe(json_decode($ciModules[1] ?? 'null', true))
        ->and(json_decode($ciModules[1] ?? 'null', true))->toBe(['passkeys', 'backups', 'impersonation']);
});

it('quotes the names in the cleanup commands the local e2e script prints (RL-6, g2-05)', function (): void {
    $script = (string) file_get_contents(base_path('scripts/e2e-local.sh'));
    $cleanup = Str::after($script, 'Clean up when you are done');

    expect($cleanup)->toContain("printf '  herd unsecure %q\\n' \"\$NAME\"")
        ->toContain("printf '  herd unisolate %q\\n' \"--site=\$NAME\"")
        ->toContain("printf '  herd unlink %q\\n' \"\$NAME\"")
        ->toContain("printf '  rm -rf %q\\n' \"\$TARGET\"")
        // --db=pgsql leaves the two databases skv:install created; the cleanup names them too (plan review K9).
        ->toContain("printf '  dropdb -h 127.0.0.1 -U root --if-exists %q\\n'")
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
