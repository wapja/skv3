<?php

declare(strict_types=1);

/** covers §5.3.1, §5.3.2, §5.3.4, §7.2 */

use App\Enums\CheckStatus;
use App\Models\User;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Detector;
use App\Setup\Doctor\Doctor;
use App\Setup\Http\EnsureSetupAccess;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupToken;
use App\Setup\StepResult;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\SetupLock;
use App\Setup\Wizard\WizardRunner;
use App\Setup\Wizard\WizardState;
use App\Tenancy\Hosts;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\FixedStatusCheck;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeader;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

$postgres = function (string $database = 'postgres'): PDO {
    /** @var array{host: string, port: string|int, username: string, password: string} $server */
    $server = config('database.connections.pgsql');

    return new PDO("pgsql:host={$server['host']};port={$server['port']};dbname={$database}", $server['username'], $server['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
};

/**
 * Opens the wizard over HTTP with the token, as a browser would, and returns its snapshot. Setup mode runs locally,
 * where VerifyCsrfToken is active: later updates send the CSRF token as Livewire's script does.
 *
 * @return array<string, mixed>
 */
$open = function (SetupContext $context): array {
    get('/welcome-setup?token='.new SetupToken($context->basePath)->value());
    withHeader('X-CSRF-TOKEN', (string) session()->token());

    return pageSnapshot(url('/welcome-setup'), 'pages::setup.wizard');
};

/**
 * The component's next snapshot and HTML from a Livewire update response.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
$next = function (TestResponse $response): array {
    /** @var array{components: list<array{snapshot: string, effects: array{html?: string}}>} $body */
    $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $snapshot */
    $snapshot = json_decode($body['components'][0]['snapshot'], true, flags: JSON_THROW_ON_ERROR);

    return [$snapshot, $body['components'][0]['effects']['html'] ?? ''];
};

/**
 * Calls advance() as the page's poll does, and complete() when $finish and the review shows, until the HTML holds
 * $marker. An update that changed nothing may render no HTML: the previous HTML then stays.
 *
 * @param  array<string, mixed>  $snapshot
 * @return array{0: array<string, mixed>, 1: string}
 */
$until = function (array $snapshot, string $html, string $marker, bool $finish = false) use ($next): array {
    for ($i = 0; $i < 20 && ! str_contains($html, $marker); $i++) {
        $action = $finish && str_contains($html, 'data-test="setup-complete"') ? 'complete' : 'advance';
        [$snapshot, $nextHtml] = $next(callPageAction($snapshot, $action)->assertOk());
        $html = $nextHtml !== '' ? $nextHtml : $html;
    }

    return [$snapshot, $html];
};

/** @var array<string, mixed> $answers */
$answers = ['appName' => 'Acme', 'database' => 'sqlite', 'startWith' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'claude' => false];

it('pre-fills the §5.3.2 questions (§5.3.2)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('/welcome-setup')->assertOk()
        ->assertSee('data-test="setup-questions"', false)
        ->assertSee(Str::headline($context->folderName()))
        // The browser tests click these (Task 13): Flux passes data-test through to the control it renders.
        ->assertSee(['data-test="setup-database-pgsql"', 'data-test="setup-database-sqlite"', 'data-test="setup-start-demo"', 'data-test="setup-start-super_admin"', 'data-test="setup-start-button"'], false)
        // The Herd stage arrives through a morph, which runs no script: its function must come with the first GET.
        ->assertSee('function setupHerdStage', false);
});

it('shows the validation errors of the answers (A15)', function () use ($open, $next): void {
    $context = useSetupWizard();
    $snapshot = $open($context);

    [, $html] = $next(callPageAction($snapshot, 'start', updates: ['startWith' => 'super_admin', 'superAdminEmail' => 'not-an-email'])->assertOk());

    // The Answers keys map onto the form's properties, so the error shows under its own field.
    expect($html)->toContain('data-test="setup-questions"')->toContain('email')
        ->toContain(__('validation.email', ['attribute' => 'super admin email']))
        ->and($context->path(WizardState::PATH))->not->toBeFile();
});

it('completes on SQLite with QUEUE_CONNECTION=database and no worker (§7.2)', function () use ($open, $next): void {
    $context = useSetupWizard();
    new EnvFile($context->path('.env'))->set(['QUEUE_CONNECTION' => 'database']);
    // The process boots as the project's .env makes it (.env.example), not with phpunit's sync queue and array
    // session: setup mode must replace them for the run, and the doctor must check the project's database queue.
    config(['queue.default' => 'database', 'session.driver' => 'database']);
    Process::fake(['*' => Process::result('')]);

    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: [
        'appName' => 'Acme', 'database' => 'sqlite', 'startWith' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'claude' => false,
    ])->assertOk());

    // The page polls advance() every 2 s; the test calls it until the Herd stage renders. The sandbox has no node, so
    // the frontend step ends manual and the page stops at the review: the user clicks "Finish setup" there.
    for ($i = 0; $i < 20 && ! str_contains($html, 'data-test="setup-herd-stage"'); $i++) {
        $action = str_contains($html, 'data-test="setup-complete"') ? 'complete' : 'advance';
        [$snapshot, $nextHtml] = $next(callPageAction($snapshot, $action)->assertOk());
        $html = $nextHtml !== '' ? $nextHtml : $html;
    }

    expect($html)->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeTrue()
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('setup.replaced.queue'))->toBe('database');
});

it('keeps the session across the environment step, so a new APP_NAME causes no 419 (§7.2)', function () use ($open, $next, $answers): void {
    $context = useSetupWizard();
    Process::fake(['*' => Process::result('')]);
    $env = new EnvFile($context->path('.env'));
    $cookie = $env->get('SESSION_COOKIE');

    [$snapshot] = $next(callPageAction($open($context), 'start', updates: [...$answers, 'appName' => 'Renamed App'])->assertOk());
    [$snapshot] = $next(callPageAction($snapshot, 'advance')->assertOk());

    expect(WizardState::load($context->basePath)?->results)->toHaveKey('environment')
        ->and($env->get('APP_NAME'))->toBe('Renamed App');

    callPageAction($snapshot, 'advance')->assertOk();

    expect($cookie)->not->toBeNull()
        ->and($env->get('SESSION_COOKIE'))->toBe($cookie);
});

it('completes on PostgreSQL without a 500 or 419 (§7.2)', function () use ($open, $next, $until, $answers, $postgres): void {
    $context = useSetupWizard(setupSandbox(prefix: 'setup_probe_'));
    $name = DatabaseStep::databaseName($context);
    $admin = $postgres();
    // Runs after afterEach's purge of the setup connection, so no session holds the databases.
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });
    Process::fake(['*' => Process::result('')]);

    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: [...$answers, 'database' => 'pgsql'])->assertOk());
    [, $html] = $until($snapshot, $html, 'data-test="setup-herd-stage"', finish: true);

    expect($html)->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeTrue()
        ->and(new EnvFile($context->path('.env'))->get('DB_DATABASE'))->toBe($name);
})->skip(fn (): bool => ! new Detector(new Toolchain)->postgresReachable(), 'PostgreSQL is not reachable');

it('shows a manual command with a re-check (§5.3.1)', function () use ($open, $next, $until, $answers): void {
    $context = useSetupWizard();
    // Node answers no version, so the frontend step returns its command as manual.
    Process::fake(['*' => Process::result('')]);

    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: $answers)->assertOk());
    [$snapshot, $html] = $until($snapshot, $html, 'data-test="setup-manual-frontend"');

    expect($html)->toContain('data-test="setup-manual-frontend"')->toContain('npm ci &amp;&amp; npm run build');

    [$snapshot, $html] = $next(callPageAction($snapshot, 'recheck', ['frontend'])->assertOk());

    expect($html)->not->toContain('data-test="setup-manual-frontend"')
        ->and(WizardState::load($context->basePath)?->results)->not->toHaveKey('frontend');

    [$snapshot, $html] = $until($snapshot, $html, 'data-test="setup-manual-frontend"');
    [$snapshot, $html] = $until($snapshot, $html, 'data-test="setup-review"');

    expect($html)->toContain('data-test="setup-review"')->toContain('data-test="setup-manual-frontend"')
        ->not->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeFalse();

    [, $html] = $next(callPageAction($snapshot, 'complete')->assertOk());

    expect($html)->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeTrue();
});

it('turns a lost setup state into a failure message, not a 500 (§5.3.1)', function () use ($open, $next, $answers): void {
    $context = useSetupWizard();
    [$snapshot] = $next(callPageAction($open($context), 'start', updates: $answers)->assertOk());
    unlink($context->path(WizardState::PATH));

    [, $html] = $next(callPageAction($snapshot, 'advance')->assertOk());

    expect($html)->toContain('data-test="setup-failure"');
});

it('ignores a second start (§5.3.4)', function () use ($open, $next, $answers): void {
    $context = useSetupWizard();
    [$snapshot] = $next(callPageAction($open($context), 'start', updates: $answers)->assertOk());

    callPageAction($snapshot, 'start', updates: [...$answers, 'appName' => 'Other'])->assertOk();

    expect(WizardState::load($context->basePath)?->answers->appName)->toBe('Acme');
});

it('refuses every action without the session token (RL-2)', function () use ($open): void {
    $snapshot = $open(useSetupWizard());
    session()->forget(EnsureSetupAccess::SESSION_KEY);

    callPageAction($snapshot, 'advance')->assertNotFound();
});

it('never stores the plaintext password (RL-5)', function () use ($open, $next, $answers): void {
    $context = useSetupWizard();

    $response = callPageAction($open($context), 'start', updates: [
        ...$answers, 'startWith' => 'super_admin', 'superAdminEmail' => 'owner@example.com', 'superAdminPassword' => 'correct-horse-battery',
    ])->assertOk();
    [$snapshot] = $next($response);

    expect((string) file_get_contents($context->path(WizardState::PATH)))->not->toContain('correct-horse-battery')
        ->and(WizardState::load($context->basePath)?->answers->superAdminPasswordHash)->not->toBeNull()
        ->and((string) $response->getContent())->not->toContain('correct-horse-battery')
        ->and($snapshot['data']['superAdminPassword'] ?? null)->toBe('');
});

it('shows a re-check that another tab blocks as a failure, not a 500 (§5.3.4)', function () use ($open, $next, $until, $answers): void {
    $context = useSetupWizard();
    Process::fake(['*' => Process::result('')]);
    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: $answers)->assertOk());
    [$snapshot] = $until($snapshot, $html, 'data-test="setup-manual-frontend"');
    // Another request holds the setup lock (flock() on its own handle conflicts with this process's too).
    $lock = fopen($context->path(SetupLock::PATH), 'c') ?: throw new LogicException('No lock file.');
    flock($lock, LOCK_EX);

    [, $html] = $next(callPageAction($snapshot, 'recheck', ['frontend'])->assertOk());

    expect($html)->toContain('data-test="setup-failure"')->toContain(__('Setup is busy in another tab.'))
        ->and(WizardState::load($context->basePath)?->results)->toHaveKey('frontend');

    flock($lock, LOCK_UN);
});

it('goes straight on to the Herd stage when no step ended manual (§5.3.1, §5.3.4)', function () use ($open, $next, $answers): void {
    // Arrange: every page step ends done or skipped: the frontend is already built (the sandbox has no node) and the
    // doctor finds nothing to fail (in the sandbox it misses node and more).
    $context = useSetupWizard();
    File::ensureDirectoryExists($context->path('public/build'));
    File::put($context->path('public/build/manifest.json'), '{}');
    app()->instance(Doctor::class, new Doctor([new FixedStatusCheck(CheckStatus::Pass, 'php')]));
    Process::fake(['*' => Process::result('')]);
    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: $answers)->assertOk());

    // Only the poll's advance(), never "Finish setup".
    for ($i = 0; $i < 20 && ! str_contains($html, 'data-test="setup-herd-stage"'); $i++) {
        expect($html)->not->toContain('data-test="setup-review"');
        [$snapshot, $nextHtml] = $next(callPageAction($snapshot, 'advance')->assertOk());
        $html = $nextHtml !== '' ? $nextHtml : $html;
    }

    expect($html)->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeTrue();
});

it('resumes at the steps after a reload, never at the questions (§5.3.4)', function () use ($open, $answers): void {
    $context = useSetupWizard();
    callPageAction($open($context), 'start', updates: $answers)->assertOk();

    $this->get('/welcome-setup')->assertOk()
        ->assertSee('data-test="setup-step-environment"', false)
        ->assertDontSee('data-test="setup-questions"', false);
});

it('titles the page once (g3-33)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('/welcome-setup')->assertOk()->assertSee('<title>'.__('Setup').'</title>', false);
});

it('names the address to use and disables the start on a host the wizard refuses (§5.3.4, g4-15)', function (): void {
    $context = useSetupWizard();
    $this->get('http://example.com/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $html = (string) $this->get('http://example.com/welcome-setup')->assertOk()
        ->assertSee(__('Open the setup on :url.', ['url' => 'http://localhost']))
        ->getContent();
    $button = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-test="setup-start-button"]');

    expect($button?->hasAttribute('disabled'))->toBeTrue();
});

it('refuses 127.0.0.1 without Herd and suggests localhost on the same port (g3-34)', function (): void {
    $context = useSetupWizard();
    $this->get('http://127.0.0.1:8000/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('http://127.0.0.1:8000/welcome-setup')->assertOk()
        ->assertSee(__('Open the setup on :url.', ['url' => 'http://localhost:8000']));
});

it('asks to rename a folder that can be no Herd address, instead of suggesting one (g3-10)', function (): void {
    $context = useSetupWizard(setupSandbox(prefix: 'my_app_'));
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home'), $context->basePath));
    Process::fake([
        "*herd' 'tld'*" => Process::result("test\n"),
        "*herd' 'services:list' '--json'*" => Process::result(''),
    ]);
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());

    $this->get('/welcome-setup')->assertOk()
        ->assertSee(__('The folder name :folder is not a valid web address under Herd. Rename the folder to :name, then start the setup again.', [
            'folder' => $context->folderName(),
            'name' => Str::slug($context->folderName()),
        ]))
        ->assertDontSee('http://'.Str::lower($context->folderName()).'.test');
});

it('turns a start that cannot take the setup lock into an error on the form, not a 500 (g3-30)', function () use ($open, $next, $answers): void {
    // Arrange: a directory stands where the setup lock goes, so opening it fails as a read-only disk would.
    $context = useSetupWizard();
    $snapshot = $open($context);
    File::ensureDirectoryExists($context->path(SetupLock::PATH));

    [, $html] = $next(callPageAction($snapshot, 'start', updates: $answers)->assertOk());

    expect($html)->toContain('data-test="setup-questions"')
        ->toContain(__('Could not write :file.', ['file' => basename(SetupLock::PATH)]))
        ->and($context->path(WizardState::PATH))->not->toBeFile();
});

it('refuses the default demo data next to an own super admin, and adds it when Start is clicked again (DEC-30, g4-04)', function () use ($open, $next, $answers): void {
    $context = useSetupWizard();
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    // The user leaves the "Start with" radio alone: no update for startWith, so the page sends no start_with.
    $untouched = array_diff_key($answers, ['startWith' => true]);

    [$snapshot, $html] = $next(callPageAction($open($context), 'start', updates: $untouched)->assertOk());

    expect($html)->toContain(__('A super admin already exists, so setup does not add the demo data by default. Click Start setup again to add it anyway, or choose My own super admin.'))
        ->and($context->path(WizardState::PATH))->not->toBeFile();

    callPageAction($snapshot, 'start')->assertOk();

    expect(WizardState::load($context->basePath)?->answers->startWith)->toBe('demo');
});

it('stops polling on a failure, and Try again runs the step again (§5.3.1, g4-13, g4-14)', function () use ($open, $next, $answers): void {
    // Arrange: PostgreSQL chosen while no server answers on this port, so the database step fails.
    $context = useSetupWizard();
    config(['database.connections.pgsql.port' => 1]);
    [$snapshot] = $next(callPageAction($open($context), 'start', updates: [...$answers, 'database' => 'pgsql'])->assertOk());
    [$snapshot] = $next(callPageAction($snapshot, 'advance')->assertOk());
    [$snapshot, $html] = $next(callPageAction($snapshot, 'advance')->assertOk());

    expect($html)->toContain('data-test="setup-failure"')->toContain('wire:click="retry"')->not->toContain('wire:poll');

    // Act: the cause is fixed (SQLite instead) and the user clicks Try again.
    $state = WizardState::load($context->basePath) ?? throw new LogicException('No setup state.');
    $state->answers = Answers::fromState([...$state->answers->toState(), 'database' => 'sqlite']);
    $state->save($context->basePath);
    [, $html] = $next(callPageAction($snapshot, 'retry')->assertOk());

    expect($html)->not->toContain('data-test="setup-failure"')->toContain('wire:poll')
        ->and(WizardState::load($context->basePath)->results)->toHaveKey('database');
});

it('runs Finish setup again from the review when Try again is clicked (§5.3.1, g4-13)', function () use ($open, $next): void {
    // Arrange: steps 1–8 have results, one of them a manual command, so the page stops at the review.
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], Detection::none(), $context->folderName());
    $results = [...array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')), 'frontend' => StepResult::manual('npm ci && npm run build', 'node is missing')];
    new WizardState('localhost', $answers, Detection::none(), $results)->save($context->basePath);
    [$snapshot, $html] = $next(callPageAction($open($context), 'advance')->assertOk());

    expect($html)->toContain('data-test="setup-review"');

    // Another request holds the setup lock, so the first Finish setup fails before the marker.
    $lock = fopen($context->path(SetupLock::PATH), 'c') ?: throw new LogicException('No lock file.');
    flock($lock, LOCK_EX);
    [$snapshot, $html] = $next(callPageAction($snapshot, 'complete')->assertOk());
    flock($lock, LOCK_UN);

    expect($html)->toContain('data-test="setup-failure"')->toContain(__('Setup is busy in another tab.'))
        ->and(Marker::exists($context))->toBeFalse();

    [, $html] = $next(callPageAction($snapshot, 'retry')->assertOk());

    expect($html)->toContain('data-test="setup-herd-stage"')
        ->and(Marker::exists($context))->toBeTrue();
});

it('offers the login page instead of Try again when the Herd stage cannot start after step 9 (g3-32)', function () use ($open, $next): void {
    // Arrange: steps 1–8 are done, and a directory stands where the Herd state goes, so finishing fails after the marker.
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => false], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    File::ensureDirectoryExists($context->path(HerdState::PATH));

    [, $html] = $next(callPageAction($open($context), 'advance')->assertOk());

    expect($html)->toContain('data-test="setup-stranded"')
        ->toContain('href="'.Hosts::url(Hosts::apex(), '/login').'"')
        ->not->toContain('wire:click="retry"')
        ->and(Marker::exists($context))->toBeTrue();
});
