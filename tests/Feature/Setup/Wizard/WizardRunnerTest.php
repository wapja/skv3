<?php

declare(strict_types=1);

/** covers §5.3.3, §5.3.4, §7.2 */

use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupToken;
use App\Setup\StepResult;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\SetupLock;
use App\Setup\Wizard\StepLauncher;
use App\Setup\Wizard\WizardRunner;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\DB;
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

it('runs steps 1 to 8 one call at a time, the long ones detached (§5.3.4, RL-4)', function (): void {
    $context = useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    $keys = [];

    while (($outcome = $runner->advance()) !== null) {
        expect($outcome['state'])->not->toBe('failed');
        $keys[] = $outcome['key'];
    }

    expect(array_values(array_unique($keys)))->toBe(WizardRunner::PAGE_STEPS)
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
});

it('marks a detached step that could not start as a manual command (RL-4)', function (): void {
    useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    while (($outcome = $runner->advance()) !== null && $outcome['key'] !== 'frontend') {
    }

    expect($runner->state()?->results['frontend']->status ?? null)->toBe('manual');
});

it('finishes with the marker, a Herd state holding the secret\'s hash and the Herd step started (RL-6)', function (): void {
    $context = useSetupWizard();
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    while ($runner->advance() !== null) {
    }

    $secret = $runner->finish();

    expect(Marker::exists($context))->toBeTrue()
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

it('applies the HTTPS consequence when the Herd step cannot start (OD-2, RL-6)', function (): void {
    $context = useSetupWizard();
    $runner = app(WizardRunner::class);
    $runner->begin(['app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['passkeys', 'impersonation'], 'https' => true, 'playwright' => false, 'claude' => false], 'localhost');
    Process::fake(['*' => Process::result('')]);

    while ($runner->advance() !== null) {
    }

    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;
    $runner->finish();

    expect(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(HerdState::load($context->basePath)?->result?->status)->toBe('manual')
        ->and((string) json_encode(Marker::read($context)))->toContain('herd secure '.$context->folderName());
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
})->with([
    'an unreadable result' => [function (SetupContext $context): void {
        File::put($context->path(WizardState::STEPS.'/frontend.json'), 'not json');
    }],
    'an exit file without a result' => [function (SetupContext $context): void {
        File::put($context->path(WizardState::STEPS.'/frontend.started'), (string) time());
        File::put($context->path(WizardState::STEPS.'/frontend.exit'), "137\n");
    }],
    'no activity for half an hour' => [function (SetupContext $context, $test): void {
        File::put($context->path(WizardState::STEPS.'/frontend.started'), (string) time());
        File::put($context->path(WizardState::STEPS.'/frontend.log'), 'npm ci');
        $test->travel(31)->minutes();
    }],
]);

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
