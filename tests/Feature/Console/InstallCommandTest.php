<?php

declare(strict_types=1);

/** covers §5.3.3, §5.3.2 */

use App\Models\User;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupRunner;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\BootstrapRunner;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\FailingBootstrap;
use Tests\Fixtures\QueryFailingSetupStep;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    // The commands a sandbox install starts (§5.3.3):
    // - the bootstrap's migration
    // - Node's version (it answers none, so the frontend step ends manual before npm)
    // - git init and the hooks path
    // - where Claude Code is on the system PATH (the claude answer then defaults to true): Boost and the plugin on a
    //   .test address, and the doctor's plugin list
    // - composer's Flux Pro credentials where Pro is installed
    Process::fake([
        "*'artisan' 'migrate'*" => Process::result(''),
        "*node' '-v'*" => Process::result(''),
        "*git' 'init'*" => Process::result(''),
        "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(''),
        "*'artisan' 'boost:install'*" => Process::result(''),
        "*claude' 'plugin'*" => Process::result('[]'),
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
    ]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    app()->instance(SetupContext::class, $this->sandbox);
    app()->instance(Bootstrap::class, new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home'))));
    app()->instance(BootstrapRunner::class, app(Bootstrap::class));
    withoutPostgresProbe();
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

/**
 * Runs skv:install into its own buffer: the steps call Artisan themselves, which replaces Artisan::output().
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
$install = function (array $parameters): array {
    $output = new BufferedOutput;
    $exitCode = Artisan::call('skv:install', $parameters, $output);

    return [$exitCode, $output->fetch()];
};

/**
 * Writes the answers to a sandbox file and returns the --answers value for it (RL-11: a password never goes inline).
 *
 * @param  array<string, mixed>  $answers
 */
$answersFile = function (SetupContext $sandbox, array $answers): string {
    $file = $sandbox->path('answers-'.Str::uuid().'.json');
    file_put_contents($file, json_encode($answers, JSON_THROW_ON_ERROR));

    return '@'.$file;
};

/**
 * Runs a command with $stdin on its input stream, as `printf … | php artisan …` would.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
$withStdin = function (string $command, array $parameters, string $stdin): array {
    $stream = fopen('php://memory', 'r+') ?: throw new LogicException('Could not open a memory stream.');
    fwrite($stream, $stdin);
    rewind($stream);
    $input = new ArrayInput(['command' => $command, ...$parameters]);
    $input->setStream($stream);
    $output = new BufferedOutput;

    return [app(Kernel::class)->handle($input, $output), $output->fetch()];
};

it('installs non-interactively from the e2e answers and writes the marker (§5.3.3, §7.2)', function () use ($install): void {
    $answers = (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json'));

    [$exitCode, $output] = $install(['--answers' => $answers, '--no-interaction' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('Summary'))->toContain('password')->toContain(__('Next steps'))->toContain('composer dev')
        ->and(Marker::exists($this->sandbox))->toBeTrue()
        ->and(new EnvFile($this->sandbox->path('.env'))->get('APP_NAME'))->toBe('E2E App');
});

it('prints every step\'s status in the active language (§5.3.3)', function () use ($install): void {
    app()->setLocale('nl');
    $answers = (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json'));

    [$exitCode, $output] = $install(['--answers' => $answers, '--no-interaction' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('klaar')->toContain('overgeslagen')
        ->and($output)->not->toMatch('/\.\s(done|skipped|manual)\s*$/m');
});

it('fails before any step when the super admin credentials are missing (§5.3.2)', function () use ($install): void {
    [$exitCode, $output] = $install(['--answers' => json_encode(['start_with' => 'super_admin'], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->not->toContain(__('Summary'))
        ->and(Marker::exists($this->sandbox))->toBeFalse()
        ->and(new EnvFile($this->sandbox->path('.env'))->get('APP_NAME'))->toBe('Laravel')
        ->and(new EnvFile($this->sandbox->path('.env'))->get('APP_KEY'))->toBe('');
});

it('rejects answers that are not JSON (§5.3.2)', function () use ($install): void {
    [$exitCode, $output] = $install(['--answers' => '{not json', '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(__('The --answers value is not valid JSON.'));
});

it('never prints the super admin password (RL-5)', function () use ($install, $answersFile): void {
    [$exitCode, $output] = $install(['--answers' => $answersFile($this->sandbox, [
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ]), '--no-interaction' => true]);

    // RL-14: the marker holds the public answers only, never the password or its hash.
    $marker = (string) file_get_contents($this->sandbox->path(Marker::FILE));

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('owner@example.com')
        ->not->toContain('correct-horse-battery')
        ->and($marker)->toContain('owner@example.com')
        ->not->toContain('correct-horse-battery')
        ->not->toContain('$2y$')
        ->not->toContain('password_hash');
});

it('stops at the step that throws and writes no marker (RL-6)', function () use ($install): void {
    // A schema dump makes the database step throw (§7.3).
    mkdir($this->sandbox->path('database/schema'), 0755, true);
    touch($this->sandbox->path('database/schema/pgsql-schema.sql'));

    [$exitCode, $output] = $install(['--answers' => json_encode(['database' => 'sqlite'], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(__('Setup stopped at :step: :message', [
            'step' => __('Database'),
            'message' => __('Remove database/schema/pgsql-schema.* first: setup runs plain migrations (§7.3).'),
        ]))
        ->and(Marker::exists($this->sandbox))->toBeFalse();
});

it('names only the driver message of a failed query, never its bindings (RL-6)', function () use ($install): void {
    app()->instance(SetupRunner::class, new SetupRunner([new QueryFailingSetupStep]));

    [$exitCode, $output] = $install(['--answers' => json_encode(['database' => 'sqlite'], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('UNIQUE constraint failed: users.email')
        ->not->toContain('secret-hash');
});

it('keeps the earlier install\'s answers on a re-run that leaves them out (§5.3.2, RL-6, DEC-30)', function () use ($install, $answersFile): void {
    [$exitCode] = $install(['--answers' => $answersFile($this->sandbox, [
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ]), '--no-interaction' => true]);

    [$rerunExitCode, $output] = $install(['--answers' => json_encode(['database' => 'sqlite'], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    /** @var array{start_with: string} $marker */
    $marker = Marker::read($this->sandbox);

    expect($exitCode)->toBe(0)
        ->and($rerunExitCode)->toBe(0)
        ->and($output)->not->toContain('superadmin@')
        ->and(DB::connection(DatabaseStep::CONNECTION)->table('users')->where('is_super_admin', true)->pluck('email')->all())->toBe(['owner@example.com'])
        ->and(new EnvFile($this->sandbox->path('.env'))->get('MODULE_IMPERSONATION'))->toBe('false')
        ->and($marker['start_with'])->toBe('super_admin');
});

it('refuses the default demo data next to an existing super admin unless asked for (§5.3.2, DEC-30)', function () use ($install): void {
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);

    [$exitCode, $output] = $install(['--answers' => json_encode(['database' => 'sqlite'], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(__('A super admin already exists, so setup does not add the demo data by default. Pass "start_with": "demo" to add it anyway, or "super_admin" with your own login.'))
        ->and(new EnvFile($this->sandbox->path('.env'))->get('APP_KEY'))->toBe('')
        ->and(Marker::exists($this->sandbox))->toBeFalse();
});

it('keeps a changed answer on the next plain re-run (§5.3.2, RL-14, DEC-30)', function () use ($install): void {
    [$exitCode] = $install(['--answers' => (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), '--no-interaction' => true]);
    [$changedExitCode, $changedOutput] = $install(['--answers' => json_encode(['modules' => []], JSON_THROW_ON_ERROR), '--no-interaction' => true]);
    [$plainExitCode] = $install(['--no-interaction' => true]);

    /** @var array{answers: array{modules: list<string>}} $marker */
    $marker = Marker::read($this->sandbox);

    expect([$exitCode, $changedExitCode, $plainExitCode])->toBe([0, 0, 0])
        // The summary's bullet list drops the closing full stop.
        ->and($changedOutput)->toContain(rtrim(__('Setup changed :keys in .env.', ['keys' => 'MODULE_IMPERSONATION']), '.'))
        ->and(new EnvFile($this->sandbox->path('.env'))->get('MODULE_IMPERSONATION'))->toBe('false')
        ->and($marker['answers']['modules'])->toBe([]);
});

it('keeps a module flag the owner switched in .env on a re-run (§6.8, DEC-30)', function (bool $force) use ($install): void {
    [$exitCode] = $install(['--answers' => (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), '--no-interaction' => true]);
    new EnvFile($this->sandbox->path('.env'))->set(['MODULE_IMPERSONATION' => false]);

    [$rerunExitCode] = $install(['--no-interaction' => true, '--force' => $force]);

    /** @var array{answers: array{modules: list<string>}} $marker */
    $marker = Marker::read($this->sandbox);

    expect([$exitCode, $rerunExitCode])->toBe([0, 0])
        ->and(new EnvFile($this->sandbox->path('.env'))->get('MODULE_IMPERSONATION'))->toBe('false')
        ->and($marker['answers']['modules'])->not->toContain('impersonation');
})->with(['plain' => false, 'forced' => true]);

it('installs on a clone whose boot config has no key and another apex than the detected host (§5.3.3, §4.12)', function () use ($install): void {
    // A clone boots before step 0 writes APP_KEY, and before step 1 writes the detected host as the apex.
    config(['app.key' => '', 'app.url' => 'http://myapp.test']);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    [$exitCode, $output] = $install(['--answers' => (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), '--no-interaction' => true]);

    $emails = DB::connection(DatabaseStep::CONNECTION)->table('users')->pluck('email')->all();

    expect($exitCode)->toBe(0)
        ->and(new EnvFile($this->sandbox->path('.env'))->get('TENANCY_APEX_DOMAIN'))->toBe('myapp.test')
        ->and($output)->toContain('superadmin@myapp.test')->toContain('admin@demo1.myapp.test')
        ->and($emails)->toContain('superadmin@myapp.test', 'admin@demo1.myapp.test', 'user@demo2.myapp.test')
        ->and(collect($emails)->filter(fn (string $email): bool => str_ends_with($email, '@localhost') || str_ends_with($email, '.localhost'))->all())->toBe([]);
});

it('applies a super admin password given for the installed account (§5.3.3 step 2)', function (bool $force) use ($install, $answersFile): void {
    [$exitCode] = $install(['--answers' => $answersFile($this->sandbox, [
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'old-password-123', 'modules' => [], 'https' => false, 'claude' => false,
    ]), '--no-interaction' => true]);

    [$rerunExitCode, $output] = $install(['--answers' => $answersFile($this->sandbox, ['super_admin_password' => 'new-password-456']), '--no-interaction' => true, '--force' => $force]);

    $owner = DB::connection(DatabaseStep::CONNECTION)->table('users')->where('email', 'owner@example.com')->sole();

    expect([$exitCode, $rerunExitCode])->toBe([0, 0])
        ->and($output)->not->toContain('new-password-456')
        ->and(Hash::check('new-password-456', (string) $owner->password))->toBeTrue()
        ->and(Hash::check('old-password-123', (string) $owner->password))->toBeFalse();
})->with(['plain' => false, 'forced' => true]);

it('reports a failing bootstrap as a setup stop without the process output (RL-13)', function () use ($install): void {
    app()->instance(BootstrapRunner::class, new FailingBootstrap);

    [$exitCode, $output] = $install(['--answers' => '{}', '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(__('Setup stopped at :step: :message', ['step' => __('Bootstrap'), 'message' => 'The command "php artisan migrate" failed.']))
        ->not->toContain('migrate failed')
        ->not->toContain('secret output');
});

it('refuses a password inside an inline --answers before anything runs (CD-8, RL-11)', function () use ($install): void {
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    [$exit, $output] = $install(['--answers' => json_encode(['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery']), '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('--answers=@')
        ->and($output)->not->toContain('correct-horse-battery')
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
});

it('reads the answers from a file with --answers=@path (RL-11)', function () use ($install): void {
    $file = $this->sandbox->path('answers.json');
    file_put_contents($file, json_encode(['database' => 'oracle']));

    [$exit, $output] = $install(['--answers' => '@'.$file, '--no-interaction' => true]);

    // The file was read: its value reaches validation.
    expect($exit)->toBe(1)->and($output)->toContain(__('validation.in', ['attribute' => 'database']));
});

it('reads the answers from standard input with --answers=- (RL-11)', function () use ($withStdin): void {
    [$exit, $output] = $withStdin('skv:install', ['--answers' => '-', '--no-interaction' => true], (string) json_encode(['database' => 'oracle']));

    expect($exit)->toBe(1)->and($output)->toContain(__('validation.in', ['attribute' => 'database']));
});

it('rejects an empty standard input for --answers=- as no JSON (RL-11, L-52)', function () use ($withStdin): void {
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    [$exit, $output] = $withStdin('skv:install', ['--answers' => '-', '--no-interaction' => true], '');

    expect($exit)->toBe(1)
        ->and($output)->toContain(__('The --answers value is not valid JSON.'))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
});

it('refuses a password of any type inside an inline --answers (CD-8, L-55)', function (mixed $password) use ($install): void {
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    [$exit, $output] = $install(['--answers' => json_encode(['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => $password], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain(__('Pass super_admin_password in a file (--answers=@answers.json) or on standard input (--answers=-), never on the command line.'))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
})->with([
    'a number' => [12345678],
    'a list' => [['correct-horse-battery']],
    'true' => [true],
]);

it('takes the super admin password from standard input with --answers=- (CD-8, RL-11, F25)', function () use ($withStdin): void {
    [$exit, $output] = $withStdin('skv:install', ['--answers' => '-', '--no-interaction' => true], json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR));

    $owner = DB::connection(DatabaseStep::CONNECTION)->table('users')->where('email', 'owner@example.com')->sole();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(__('Pass super_admin_password in a file (--answers=@answers.json) or on standard input (--answers=-), never on the command line.'))
        ->not->toContain('correct-horse-battery')
        ->and(Hash::check('correct-horse-battery', (string) $owner->password))->toBeTrue();
});

it('re-runs a complete step only with --force (RL-6, g2-21)', function () use ($install): void {
    $answers = (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json'));
    // The herd.yml step's line in the non-interactive output: "herd.yml ....... <status>".
    $status = fn (string $output): ?string => preg_match('/^\s*'.preg_quote(__('herd.yml'), '/').'\s+\.+\s+(\S+)\s*$/m', $output, $match) === 1 ? $match[1] : null;

    [$firstExit, $first] = $install(['--answers' => $answers, '--no-interaction' => true]);
    [$plainExit, $plain] = $install(['--no-interaction' => true]);
    [$forcedExit, $forced] = $install(['--no-interaction' => true, '--force' => true]);

    expect([$firstExit, $plainExit, $forcedExit])->toBe([0, 0, 0])
        ->and([$status($first), $status($plain), $status($forced)])->toBe([__('done'), __('skipped'), __('done')]);
});

it('names an unreadable answers file (RL-11)', function () use ($install): void {
    [$exit, $output] = $install(['--answers' => '@'.$this->sandbox->path('missing.json'), '--no-interaction' => true]);

    // The console components print paths inside the project relative to it (EnsureRelativePaths).
    expect($exit)->toBe(1)->and($output)->toContain(__('The answers file :path cannot be read.', ['path' => Str::after($this->sandbox->path('missing.json'), base_path().'/')]));
});

it('defaults to SQLite whatever PostgreSQL the machine runs (g1-13, RL-3)', function () use ($install): void {
    /** @var array<string, mixed> $answers */
    $answers = json_decode((string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), true, flags: JSON_THROW_ON_ERROR);
    unset($answers['database']);

    [$exitCode] = $install(['--answers' => json_encode($answers, JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    expect($exitCode)->toBe(0)
        ->and(new EnvFile($this->sandbox->path('.env'))->get('DB_CONNECTION'))->toBe('sqlite');
});

it('refuses a folder name that can be no Herd address before anything runs (g3-10)', function () use ($install): void {
    $sandbox = setupSandbox(prefix: 'my_app_');
    $herd = $sandbox->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($sandbox->path('home')));
    app()->instance(SetupContext::class, $sandbox);
    $before = (string) file_get_contents($sandbox->path('.env'));

    [$exitCode, $output] = $install(['--answers' => (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(Str::slug($sandbox->folderName()))
        ->and(Marker::exists($sandbox))->toBeFalse()
        ->and(file_get_contents($sandbox->path('.env')))->toBe($before);
});
