<?php

declare(strict_types=1);

/** covers §5.3.3, §5.3.2 */

use App\Models\User;
use App\Setup\Detector;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupRunner;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\BootstrapRunner;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\FailingBootstrap;
use Tests\Fixtures\QueryFailingSetupStep;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    app()->instance(SetupContext::class, $this->sandbox);
    app()->instance(Bootstrap::class, new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home'))));
    app()->instance(BootstrapRunner::class, app(Bootstrap::class));
    app()->instance(Detector::class, new Detector(new Toolchain($this->sandbox->path('home'))));
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

it('installs non-interactively from the e2e answers and writes the marker (§5.3.3, §7.2)', function () use ($install): void {
    $answers = (string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json'));

    [$exitCode, $output] = $install(['--answers' => $answers, '--no-interaction' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('Summary'))->toContain('password')
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

it('never prints the super admin password (RL-5)', function () use ($install): void {
    [$exitCode, $output] = $install(['--answers' => json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

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

it('keeps the earlier install\'s answers on a re-run that leaves them out (§5.3.2, RL-6, DEC-30)', function () use ($install): void {
    [$exitCode] = $install(['--answers' => json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

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

it('applies a super admin password given for the installed account (§5.3.3 step 2)', function (bool $force) use ($install): void {
    [$exitCode] = $install(['--answers' => json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'old-password-123', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR), '--no-interaction' => true]);

    [$rerunExitCode, $output] = $install(['--answers' => json_encode(['super_admin_password' => 'new-password-456'], JSON_THROW_ON_ERROR), '--no-interaction' => true, '--force' => $force]);

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
