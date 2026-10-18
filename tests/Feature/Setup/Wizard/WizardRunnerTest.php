<?php

declare(strict_types=1);

/** covers §5.3.3, §5.3.4, §7.2 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Detector;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupToken;
use App\Setup\StepResult;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\SetupLock;
use App\Setup\Wizard\StepLauncher;
use App\Setup\Wizard\WizardRunner;
use App\Setup\Wizard\WizardState;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\InlineStepLauncher;

/**
 * Holds the setup lock as another request would (flock() on its own handle, which conflicts with any other handle,
 * even in this process) and returns the handle to release.
 *
 * @return resource
 */
$holdLock = function (string $basePath) {
    $handle = fopen(rtrim($basePath, '/').'/'.SetupLock::PATH, 'c') ?: throw new LogicException('No lock file.');
    flock($handle, LOCK_EX);

    return $handle;
};

/**
 * The commands a wizard run without Herd starts (§5.3.3): Node answers no version, so the frontend step ends manual
 * before npm; git init and the hooks path; composer's Flux Pro credentials where Pro is installed (the doctor step).
 *
 * @return array<string, FakeProcessResult>
 */
$wizardCommands = fn (): array => [
    "*node' '-v'*" => Process::result(''),
    "*git' 'init'*" => Process::result(''),
    "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(''),
    "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
];

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

it('refuses a host that does not belong to this project (A4, RL-3)', function (): void {
    useSetupWizard();

    expect(fn () => app(WizardRunner::class)->begin(['database' => 'sqlite'], 'example.com'))
        ->toThrow(ValidationException::class);
});

it('stores the answers with the password hash only (RL-5)', function (): void {
    $context = useSetupWizard();

    app(WizardRunner::class)->begin([
        'app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery',
        'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false,
    ], 'localhost');

    expect(WizardState::load($context->basePath)?->host)->toBe('localhost')
        ->and((string) file_get_contents($context->path(WizardState::PATH)))->not->toContain('correct-horse-battery');
});

it('runs steps 1 to 8 one call at a time, the long ones detached (§5.3.4, RL-4)', function () use ($wizardCommands): void {
    $context = useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake($wizardCommands());

    $keys = [];

    // Bounded, so a step that keeps answering "running" fails the test instead of hanging the worker (g4-21).
    for ($i = 0; $i < 50 && ($outcome = $runner->advance()) !== null; $i++) {
        expect($outcome['state'])->not->toBe('failed');
        $keys[] = $outcome['key'];
    }

    expect($i)->toBeLessThan(50)
        ->and(array_values(array_unique($keys)))->toBe(WizardRunner::PAGE_STEPS)
        ->and($launcher->started)->toContain('frontend')
        ->and(WizardState::load($context->basePath)?->results)->toHaveKeys(WizardRunner::PAGE_STEPS);
});

it('leaves a console process without a time limit, so a test worker is never cut off after a step (RL-4)', function (): void {
    useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');

    expect($runner->advance()['key'] ?? null)->toBe('environment')
        ->and(ini_get('max_execution_time'))->toBe('0');
});

it('reports a failing step without losing the earlier results, then retries it (§5.3.1)', function (): void {
    // Arrange: begin() with pgsql while no Postgres answers here, so the database step throws.
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'pgsql', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    config(['database.connections.pgsql.port' => 1]);

    expect($runner->advance()['key'] ?? null)->toBe('environment');

    $failed = $runner->advance();

    expect($failed['key'] ?? null)->toBe('database')
        ->and($failed['state'] ?? null)->toBe('failed')
        ->and(WizardState::load($context->basePath)?->results)->toHaveKey('environment')->not->toHaveKey('database');

    // Act: the cause is fixed (SQLite instead of the server that does not answer) and the page asks again (g4-20).
    $state = WizardState::load($context->basePath) ?? throw new LogicException('No setup state.');
    $state->answers = Answers::fromState([...$state->answers->toState(), 'database' => 'sqlite']);
    $state->save($context->basePath);
    $retried = $runner->advance();

    expect($retried['key'] ?? null)->toBe('database')
        ->and($retried['state'] ?? null)->toBe('done')
        ->and(WizardState::load($context->basePath)->results)->toHaveKeys(['environment', 'database']);
});

it('marks a detached step that could not start as a manual command (RL-4)', function () use ($wizardCommands): void {
    useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake($wizardCommands());

    for ($i = 0; $i < 50 && ($outcome = $runner->advance()) !== null && $outcome['key'] !== 'frontend'; $i++) {
    }

    expect($i)->toBeLessThan(50)
        ->and($runner->state()?->results['frontend']->status ?? null)->toBe('manual');
});

it('finishes with the marker, a Herd state holding the secret\'s hash and the Herd step started (RL-6)', function () use ($wizardCommands): void {
    $context = useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake($wizardCommands());

    for ($i = 0; $i < 50 && $runner->advance() !== null; $i++) {
    }

    $secret = $runner->finish();

    expect($i)->toBeLessThan(50)
        ->and(Marker::exists($context))->toBeTrue()
        ->and(new SetupToken($context->basePath)->exists())->toBeFalse()
        ->and(WizardState::load($context->basePath))->toBeNull()
        ->and(HerdState::load($context->basePath)?->matches($secret))->toBeTrue()
        ->and($launcher->started)->toContain('herd-site');
});

it('refuses a second start while a setup is in progress (§5.3.4)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');

    expect(fn () => $runner->begin(['app_name' => 'Other', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => [], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost'))
        ->toThrow(ValidationException::class)
        ->and(WizardState::load($context->basePath)?->answers->appName)->toBe('Acme');
});

it('forgets a page step\'s result and its files on a re-check (§5.3.4)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $state = $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = ['environment' => StepResult::done('ok'), 'frontend' => StepResult::done('built')];
    $state->save($context->basePath);
    @mkdir($context->path(WizardState::STEPS), 0755, true);
    file_put_contents($context->path(WizardState::STEPS.'/frontend.json'), '{}');
    file_put_contents($context->path(WizardState::STEPS.'/frontend.log'), 'npm ci');

    $runner->recheck('frontend');

    expect(WizardState::load($context->basePath)?->results)->toHaveKey('environment')->not->toHaveKey('frontend')
        ->and($context->path(WizardState::STEPS.'/frontend.json'))->not->toBeFile()
        ->and($context->path(WizardState::STEPS.'/frontend.log'))->not->toBeFile();
});

it('ignores a re-check of a key that is not a page step, so a client-sent path never deletes a file (§5.3.4)', function (string $key, string $victim): void {
    // Arrange: the steps directory exists, so a traversing key would resolve to a real file outside it.
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $state = $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = ['environment' => StepResult::done('ok')];
    $state->save($context->basePath);
    @mkdir($context->path(WizardState::STEPS), 0755, true);
    file_put_contents($context->path($victim), '{"keep": true}');
    $before = (string) file_get_contents($context->path(WizardState::PATH));

    $runner->recheck($key);

    expect($context->path($victim))->toBeFile()
        ->and((string) file_get_contents($context->path(WizardState::PATH)))->toBe($before);
})->with([
    'a path out of the steps directory' => ['../../../../victim', 'victim.json'],
    'a step outside the page' => ['marker', WizardState::STEPS.'/marker.json'],
]);

it('settles at once without HTTPS when the Herd step cannot start without Herd (OD-2, RL-7, g3-22)', function (): void {
    // Arrange: steps 1–8 are done and there is no Herd, so no Herd command is left for the user to run.
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation'], 'https' => true], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;

    app(WizardRunner::class)->finish();

    expect(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(HerdState::load($context->basePath)?->result?->toArray())->toBe(['status' => 'skipped', 'message' => __('The Herd step could not start in the background.'), 'command' => null])
        ->and($context->path(HerdState::EXIT))->not->toBeFile();
});

it('reports the next step as running and runs nothing while another request holds the lock (§5.3.4)', function () use ($holdLock): void {
    // Arrange: a reload while the database step runs: the old request still holds the lock.
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $lock = $holdLock($context->basePath);

    $outcome = $runner->advance();

    expect($outcome)->toBe(['key' => 'environment', 'state' => 'running', 'message' => ''])
        ->and(WizardState::load($context->basePath)?->results)->toBe([]);

    flock($lock, LOCK_UN);

    expect($runner->advance()['state'] ?? null)->toBe('done');
});

it('refuses a re-check and finishing while another request holds the lock (§5.3.4)', function () use ($holdLock): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $state = $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok'));
    $state->save($context->basePath);
    $lock = $holdLock($context->basePath);

    expect(fn () => $runner->recheck('frontend'))->toThrow(RuntimeException::class, __('Setup is busy in another tab.'))
        ->and(fn () => $runner->finish())->toThrow(RuntimeException::class, __('Setup is busy in another tab.'))
        ->and(WizardState::load($context->basePath)?->results)->toHaveKey('frontend')
        ->and(Marker::exists($context))->toBeFalse();

    flock($lock, LOCK_UN);
});

/**
 * A detached step whose log has been quiet for half an hour and whose `<step>.started` holds $pid: the wizard writes it
 * empty, and a shell that never started leaves it so. 0 would signal this process group, and 1 is launchd or init.
 * The pid 1 row pins the `$pid > 1` guard only when the suite runs as root: any other user may not signal pid 1, so
 * posix_kill() already answers false there.
 */
$quietFor31Minutes = fn (string $pid): Closure => function (SetupContext $context, $test) use ($pid): void {
    File::put($context->path(WizardState::STEPS.'/frontend.started'), $pid);
    File::put($context->path(WizardState::STEPS.'/frontend.log'), 'npm ci');
    $test->travel(31)->minutes();
};

/** How a detached step's files say that its process ended without a usable result. */
$diedSteps = [
    'an unreadable result' => [function (SetupContext $context): void {
        File::put($context->path(WizardState::STEPS.'/frontend.json'), 'not json');
    }],
    'an exit file without a result' => [function (SetupContext $context): void {
        File::put($context->path(WizardState::STEPS.'/frontend.started'), (string) time());
        File::put($context->path(WizardState::STEPS.'/frontend.exit'), "137\n");
    }],
    'no activity for half an hour, no pid' => [$quietFor31Minutes('')],
    'no activity for half an hour, pid 0' => [$quietFor31Minutes("0\n")],
    'no activity for half an hour, pid 1 (pins the guard only as root)' => [$quietFor31Minutes("1\n")],
];

it('records a detached step that died as its manual command (§5.3.1, RL-4)', function (Closure $died): void {
    // Arrange: steps 1–4 are done, so the frontend step comes next and its files say how its process ended.
    $context = useSetupWizard();
    $launcher = new InlineStepLauncher;
    app()->instance(StepLauncher::class, $launcher);
    $runner = app(WizardRunner::class);
    $state = $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = array_fill_keys(['environment', 'database', 'herd-manifest', 'branding'], StepResult::done('ok'));
    $state->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));
    $died($context, $this);

    $outcome = $runner->advance();

    expect($outcome['key'] ?? null)->toBe('frontend')
        ->and($runner->state()?->results['frontend']->toArray() ?? [])->toMatchArray(['status' => 'manual', 'command' => 'npm ci && npm run build'])
        ->and($launcher->started)->toBe([]);
})->with($diedSteps);

it('keeps polling a detached step that shows activity within half an hour (RL-4)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $state = $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = array_fill_keys(['environment', 'database', 'herd-manifest', 'branding'], StepResult::done('ok'));
    $state->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));
    File::put($context->path(WizardState::STEPS.'/frontend.started'), (string) time());
    $this->travel(29)->minutes();

    expect($runner->advance())->toBe(['key' => 'frontend', 'state' => 'running', 'message' => ''])
        ->and($runner->state()?->results)->not->toHaveKey('frontend');
});

it('refuses every host while herd tld gives no answer, never falling back to the names without Herd (g3-28)', function (): void {
    $context = useSetupWizard();
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home'), $context->basePath));
    Process::fake([
        "*herd' 'tld'*" => Process::result(exitCode: 1),
        "*herd' 'services:list' '--json'*" => Process::result(''),
    ]);

    expect(fn () => app(WizardRunner::class)->begin(['database' => 'sqlite'], 'admin.'.Str::lower($context->folderName()).'.test'))
        ->toThrow(ValidationException::class, __('Herd did not answer. Reload the page in a moment.'))
        ->and($context->path(WizardState::PATH))->not->toBeFile();
});

/** A wizard state whose steps 1–4 are done, so the frontend step (long) comes next; returns the steps directory. */
$beforeFrontend = function (SetupContext $context): string {
    $state = app(WizardRunner::class)->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = array_fill_keys(['environment', 'database', 'herd-manifest', 'branding'], StepResult::done('ok'));
    $state->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));

    return $context->path(WizardState::STEPS);
};

it('records the result of a detached step that exited (RL-4, g3-11)', function () use ($beforeFrontend): void {
    // advanceLong() reads the exit file before the result: the child writes its result first, so a result written
    // between the two reads is never taken for "stopped without a result". That race cannot be made deterministic
    // here; this pins the branch it ends in.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.json', json_encode([...StepResult::done('built')->toArray(), 'finished_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
    File::put($steps.'/frontend.exit', "0\n");

    expect(app(WizardRunner::class)->advance())->toBe(['key' => 'frontend', 'state' => 'done', 'message' => 'built'])
        ->and(app(WizardRunner::class)->state()?->results['frontend']->status ?? null)->toBe('done');
});

it('keeps reporting a quiet detached step as running while its process lives (RL-8, g3-12)', function () use ($beforeFrontend): void {
    // Arrange: this test's own pid stands for the launcher's shell, which is still alive.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.started', getmypid()."\n");
    File::put($steps.'/frontend.log', 'npm ci');
    $this->travel(31)->minutes();

    expect(app(WizardRunner::class)->advance())->toBe(['key' => 'frontend', 'state' => 'running', 'message' => ''])
        ->and(app(WizardRunner::class)->state()?->results)->not->toHaveKey('frontend');
});

it('refuses a re-check while the step\'s process lives, so no second one starts (RL-8, g3-12)', function () use ($beforeFrontend): void {
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    $state = app(WizardRunner::class)->state() ?? throw new LogicException('No setup state.');
    $state->results['frontend'] = StepResult::manual('npm ci && npm run build', 'The step has not finished.');
    $state->save($context->basePath);
    File::put($steps.'/frontend.started', getmypid()."\n");
    File::put($steps.'/frontend.log', 'npm ci');

    expect(fn () => app(WizardRunner::class)->recheck('frontend'))->toThrow(RuntimeException::class, __('This step is still running.'))
        ->and($steps.'/frontend.started')->toBeFile()
        ->and($steps.'/frontend.log')->toBeFile()
        ->and(app(WizardRunner::class)->state()->results)->toHaveKey('frontend');
});

it('refuses to finish while a long step\'s process lives, so step 9 never deletes the steps under it (RL-8)', function () use ($beforeFrontend): void {
    // Arrange: every page step has a result, but the frontend step's shell (this test's own pid) still runs.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    $state = app(WizardRunner::class)->state() ?? throw new LogicException('No setup state.');
    $state->results = array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok'));
    $state->results['frontend'] = StepResult::manual('npm ci && npm run build', 'The step has not finished.');
    $state->save($context->basePath);
    File::put($steps.'/frontend.started', getmypid()."\n");
    File::put($steps.'/frontend.log', 'npm ci');

    expect(fn () => app(WizardRunner::class)->finish())->toThrow(RuntimeException::class, __('This step is still running.'))
        ->and(Marker::exists($context))->toBeFalse()
        ->and($steps.'/frontend.started')->toBeFile()
        ->and(HerdState::load($context->basePath))->toBeNull();
});

it('re-checks a step that wrote its exit file, even while another process has its pid (RL-8)', function () use ($beforeFrontend): void {
    // Arrange: the step's shell wrote its exit code, and its pid now belongs to a live process (this test's own).
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    $state = app(WizardRunner::class)->state() ?? throw new LogicException('No setup state.');
    $state->results['frontend'] = StepResult::manual('npm ci && npm run build', 'The step stopped without a result.');
    $state->save($context->basePath);
    File::put($steps.'/frontend.started', getmypid()."\n");
    File::put($steps.'/frontend.exit', "137\n");

    app(WizardRunner::class)->recheck('frontend');

    expect($steps.'/frontend.started')->not->toBeFile()
        ->and($steps.'/frontend.exit')->not->toBeFile()
        ->and(app(WizardRunner::class)->state()?->results)->not->toHaveKey('frontend');
});

it('stops trusting the pid four hours after the step started, so a reused pid never wedges the step (RL-8)', function () use ($beforeFrontend): void {
    // Arrange: the shell died without writing the exit file, and its pid now belongs to another live process
    // (this test's own); the step started five hours ago and its log is gone.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.started', getmypid()."\n");
    touch($steps.'/frontend.started', time() - 5 * 3600);

    expect(app(WizardRunner::class)->advance())->toBe([
        'key' => 'frontend',
        'state' => 'done',
        'message' => __('The step has not finished; see :log.', ['log' => WizardState::STEPS.'/frontend.log']),
    ])->and(app(WizardRunner::class)->state()?->results['frontend']->status ?? null)->toBe('manual');

    app(WizardRunner::class)->recheck('frontend');

    expect($steps.'/frontend.started')->not->toBeFile()
        ->and(app(WizardRunner::class)->state()?->results)->not->toHaveKey('frontend');
});

it('never takes a garbled pid in the started file for a live step process (RL-8, L7b-33)', function (Closure $pid) use ($beforeFrontend): void {
    // Arrange: a quiet step whose started file holds something the launcher's shell never writes, past the stall bound.
    $context = useSetupWizard();
    $steps = $beforeFrontend($context);
    File::put($steps.'/frontend.started', $pid()."\n");
    File::put($steps.'/frontend.log', 'npm ci');
    $this->travel(31)->minutes();

    expect(app(WizardRunner::class)->advance())->toBe([
        'key' => 'frontend',
        'state' => 'done',
        'message' => __('The step has not finished; see :log.', ['log' => WizardState::STEPS.'/frontend.log']),
    ])->and(app(WizardRunner::class)->state()?->results['frontend']->status ?? null)->toBe('manual');
})->with([
    // posix_kill() narrows it to pid_t: -1, which signals every process this user owns, so kill() succeeds.
    'a number past pid_t' => [fn (): string => '4294967295'],
    // (int) would read this test's own, live pid.
    'a live pid with trailing garbage' => [fn (): string => getmypid().'x'],
]);

it('detects the machine once per setup, not on every poll (CD-15, g3-29)', function (): void {
    // Arrange: count the Detector's PostgreSQL probes, one per detect().
    $context = useSetupWizard();
    $probes = 0;
    app()->instance(Detector::class, new Detector(app(Toolchain::class), postgresProbe: function () use (&$probes): bool {
        $probes++;

        return false;
    }));
    $runner = app(WizardRunner::class);

    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $runner->advance();
    $runner->advance();
    $runner->advance();

    expect($probes)->toBe(1)
        ->and(WizardState::load($context->basePath)?->results)->toHaveKeys(['environment', 'database', 'herd-manifest']);
});

it('reports a long step whose completion check throws as failed, not a 500, and logs the unexpected error (§5.3.1, g3-31, M8)', function (): void {
    // Arrange: steps 1–6 are done, so the Claude step (long) comes next, and its check reads a broken composer.json.
    Exceptions::fake();
    $context = useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $runner = app(WizardRunner::class);
    $state = $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    $state->results = array_fill_keys(['environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git'], StepResult::done('ok'));
    $state->save($context->basePath);
    File::put($context->path('composer.json'), '{');

    $outcome = $runner->advance();

    expect($outcome['key'] ?? null)->toBe('claude')
        ->and($outcome['state'] ?? null)->toBe('failed')
        ->and($runner->state()?->results)->not->toHaveKey('claude')
        ->and($launcher->started)->toBe([]);
    Exceptions::assertReported(JsonException::class);
});

it('records what the Herd step did not do in the marker when the Herd stage cannot start after step 9 (g3-32)', function (): void {
    // Arrange: steps 1–8 are done, and a directory stands where the Herd state goes, so finishing fails after the marker.
    $context = useSetupWizard();
    new EnvFile($context->path('.env'))->set(['MODULE_PASSKEYS' => true]);
    $answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation'], 'https' => true], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    File::ensureDirectoryExists($context->path(HerdState::PATH));

    $thrown = null;

    try {
        app(WizardRunner::class)->finish();
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('false');
});

it('throws the Herd stage\'s own failure and reports the stranding\'s when both fail after step 9 (g3-32, L7b-28)', function (): void {
    // Arrange: steps 1–8 are done and HTTPS with passkeys was chosen; a directory stands where the Herd state goes, so
    // HerdState::begin() fails after the marker, and .env is gone, so switching passkeys off while stranding fails too.
    Exceptions::fake();
    $context = useSetupWizard();
    $answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation'], 'https' => true], Detection::none(), $context->folderName());
    new WizardState('localhost', $answers, Detection::none(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    File::ensureDirectoryExists($context->path(HerdState::PATH));
    File::delete($context->path('.env'));

    expect(fn () => app(WizardRunner::class)->finish())
        ->toThrow(RuntimeException::class, __('Could not write :file.', ['file' => basename(HerdState::PATH)]))
        ->and(Marker::exists($context))->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === __('The environment file [:path] does not exist.', ['path' => $context->path('.env')]));
});

it('writes the port the wizard was opened on into APP_URL without Herd (CD-15, g1-17)', function (): void {
    // Arrange: .env still has the APP_URL of .env.example, without a port.
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost:8000');

    expect($runner->advance()['key'] ?? null)->toBe('environment')
        ->and(WizardState::load($context->basePath)?->detection->port)->toBe(8000)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('http://localhost:8000');
});
