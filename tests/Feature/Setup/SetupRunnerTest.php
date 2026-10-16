<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Enums\CheckStatus;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Doctor\Checks\BackupPasswordCheck;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Marker;
use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Steps\EnvironmentStep;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Summary;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Fixtures\RecordingSetupStep;
use Tests\Fixtures\ThrowingCompletenessStep;

/** @param  ArrayObject<int, string>  $ran */
$fakeStep = fn (string $key, ArrayObject $ran, ?StepResult $result = null, bool $complete = false): SetupStep => new RecordingSetupStep($key, $ran, $result ?? StepResult::done(), $complete);

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('runs the eleven steps in spec order (§5.3.3)', function (): void {
    expect(array_map(fn (SetupStep $step): string => $step->key(), app(SetupRunner::class)->steps()))->toBe([
        'environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git', 'claude', 'doctor', 'marker', 'herd-site', 'summary',
    ]);
});

it('skips complete steps unless forced, and keeps going after a manual step (RL-6)', function () use ($fakeStep): void {
    $context = setupSandbox();
    $ran = new ArrayObject;
    $runner = new SetupRunner([$fakeStep('a', $ran, complete: true), $fakeStep('b', $ran, StepResult::manual('do-it', 'why')), $fakeStep('c', $ran)]);

    $runner->run($context);

    expect($ran->getArrayCopy())->toBe(['b', 'c'])
        ->and($context->results['a']->status)->toBe('skipped')
        ->and($context->results['b']->isManual())->toBeTrue();
});

it('re-runs complete steps when forced (RL-6)', function () use ($fakeStep): void {
    $context = setupSandbox();
    $context->force = true;
    $ran = new ArrayObject;

    new SetupRunner([$fakeStep('a', $ran, complete: true)])->run($context);

    expect($ran->getArrayCopy())->toBe(['a'])
        ->and($context->results['a']->status)->toBe('done');
});

it('wraps every step in the given closure (§5.3.3)', function () use ($fakeStep): void {
    $context = setupSandbox();
    $ran = new ArrayObject;
    $wrapped = [];

    new SetupRunner([$fakeStep('a', $ran), $fakeStep('b', $ran, complete: true)])->run($context, function (SetupStep $step, Closure $execute) use (&$wrapped): StepResult {
        $wrapped[] = $step->key();

        return $execute();
    });

    expect($wrapped)->toBe(['a', 'b'])
        ->and($ran->getArrayCopy())->toBe(['a']);
});

it('turns passkeys off when HTTPS was wanted but the site could not be secured (§6.8, RL-7)', function (): void {
    $context = setupSandbox();
    $context->results['herd-site'] = StepResult::manual('herd secure x', 'not confirmed');
    Marker::write($context, []);

    app(SetupRunner::class)->applyHttpsConsequence($context);

    /** @var array{answers: array<string, mixed>, summary: array{notes?: list<string>}} $marker */
    $marker = Marker::read($context);

    expect(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('false')
        ->and($context->answers->moduleEnabled('passkeys'))->toBeFalse()
        ->and($context->answers->https)->toBeFalse()
        ->and(implode(' ', Summary::build($context)['notes']))->toContain('MODULE_PASSKEYS=true')
        ->and($marker['answers']['https'] ?? null)->toBeFalse()
        ->and(implode(' ', $marker['summary']['notes'] ?? []))->toContain('MODULE_PASSKEYS=true');
});

it('keeps passkeys on when the site was secured (RL-7)', function (): void {
    $context = setupSandbox();
    $context->results['herd-site'] = StepResult::done('secured: Site secured with HTTPS');

    app(SetupRunner::class)->applyHttpsConsequence($context);

    expect(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('true')
        ->and($context->answers->moduleEnabled('passkeys'))->toBeTrue()
        ->and($context->notes)->toBe([]);
});

it('keeps passkeys on when the Herd step was already complete (RL-7)', function () use ($fakeStep): void {
    $context = setupSandbox();
    $before = new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS');

    new SetupRunner([$fakeStep('herd-site', new ArrayObject, complete: true)])->run($context);

    expect($before)->toBe('true')
        ->and(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe($before)
        ->and($context->answers->moduleEnabled('passkeys'))->toBeTrue();
});

it('applies the HTTPS consequence after a Herd step that ran without securing the site (RL-7)', function () use ($fakeStep): void {
    $context = setupSandbox();

    new SetupRunner([$fakeStep('herd-site', new ArrayObject, StepResult::manual('herd secure x', 'not confirmed'))])->run($context);

    expect(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('false')
        ->and($context->answers->moduleEnabled('passkeys'))->toBeFalse();
});

it('keeps passkeys on without Herd when APP_URL already is https on the detected host (§5.3.4, RL-7)', function (): void {
    // A TLS-terminating deployment: no Herd, served over https by something else.
    $detection = Detection::none('app.example.com');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['modules' => ['passkeys']], $detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'https://app.example.com']);

    new SetupRunner([new EnvironmentStep, new HerdSiteStep])->run($context);

    expect($context->results['herd-site']->status)->toBe('skipped')
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://app.example.com')
        ->and(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('true')
        ->and($context->answers->moduleEnabled('passkeys'))->toBeTrue()
        ->and($context->notes)->toBe([]);
});

it('still turns passkeys off without Herd while APP_URL is http (§5.3.4, RL-7)', function (): void {
    $detection = Detection::none('app.example.com');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['modules' => ['passkeys']], $detection, $context->folderName());
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://app.example.com']);

    new SetupRunner([new EnvironmentStep, new HerdSiteStep])->run($context);

    expect(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->toBe('false')
        ->and($context->answers->moduleEnabled('passkeys'))->toBeFalse();
});

it('runs one step at a time with the same rules as a full run (A12)', function () use ($fakeStep): void {
    $context = setupSandbox();
    $ran = new ArrayObject;
    $runner = new SetupRunner;

    $skipped = $runner->runStep($context, $fakeStep('a', $ran, complete: true));
    $done = $runner->runStep($context, $fakeStep('b', $ran));

    expect($skipped->status)->toBe('skipped')
        ->and($done->status)->toBe('done')
        ->and($ran->getArrayCopy())->toBe(['b'])
        ->and(array_keys($context->results))->toBe(['a', 'b']);
});

it('points the running configuration at the archive password the environment step writes (CD-10)', function (): void {
    // skv:install runs every step in one process: its doctor step must see the password as the next process will.
    useBackupsModule(true);
    config(['backup.backup.password' => null]);
    $context = setupSandbox();

    new SetupRunner([])->runStep($context, new EnvironmentStep);

    expect(config('backup.backup.password'))->toBe(new EnvFile($context->path('.env'))->get('BACKUP_ARCHIVE_PASSWORD'))
        ->and(new BackupPasswordCheck()->run(DoctorContext::fromSetup($context, app(Toolchain::class)))->status)->toBe(CheckStatus::Pass);
});

it('hands the step to the wrapper before it checks completeness, so a throwing check is named (A12)', function (): void {
    $context = setupSandbox();
    $named = null;
    $step = new ThrowingCompletenessStep;

    // A plain closure: an arrow function would capture $named by value, so the wrapper's write would never arrive.
    $run = function () use ($step, $context, &$named): void {
        new SetupRunner([$step])->run($context, function (SetupStep $current, Closure $execute) use (&$named): StepResult {
            $named = $current->key();

            return $execute();
        });
    };

    expect($run)->toThrow(RuntimeException::class);
    expect($named)->toBe('throws-on-check');
});

it('declares which steps run detached in the browser (A25, §5.3.1)', function (string $key, bool $long): void {
    $step = collect(app(SetupRunner::class)->steps())->first(fn (SetupStep $step): bool => $step->key() === $key);

    expect($step?->isLong())->toBe($long);
})->with([
    ['environment', false], ['database', false], ['herd-manifest', false], ['branding', false], ['frontend', true],
    ['git', false], ['claude', true], ['doctor', false], ['marker', false], ['herd-site', false], ['summary', false],
]);

it('gives every step a key a step file may carry and a translated label (§5.3.1, g1-23)', function (string $key): void {
    /** @var array<string, string> $dutch */
    $dutch = json_decode((string) file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
    $step = collect(app(SetupRunner::class)->steps())->firstOrFail(fn (SetupStep $step): bool => $step->key() === $key);

    app()->setLocale('en');
    $english = $step->label();
    app()->setLocale('nl');

    // WizardRunner names a step's files after its key and refuses anything else (stepFile()).
    expect($step->key())->toMatch('/^[a-z][a-z-]*$/')
        ->and(array_key_exists($english, $dutch))->toBeTrue()
        ->and($step->label())->toBe($dutch[$english] ?? null);
})->with(['environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git', 'claude', 'doctor', 'marker', 'herd-site', 'summary']);

it('is complete right after a run that returned done, so a re-run skips it (§5.3.1, g1-23)', function (string $key): void {
    $context = setupSandbox();
    $step = collect(app(SetupRunner::class)->steps())->firstOrFail(fn (SetupStep $step): bool => $step->key() === $key);

    $result = $step->run($context);

    expect($result->status)->toBe('done')
        ->and($step->isComplete($context))->toBeTrue();
})->with(['environment', 'herd-manifest', 'branding', 'marker']);

it('never counts the doctor and the summary as complete: they report on every run (§5.3.1, g1-23)', function (string $key): void {
    $step = collect(app(SetupRunner::class)->steps())->firstOrFail(fn (SetupStep $step): bool => $step->key() === $key);

    expect($step->isComplete(setupSandbox()))->toBeFalse();
})->with(['doctor', 'summary']);

it('records an unsecured site as http even without passkeys (OD-2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    $context->results['herd-site'] = StepResult::manual('herd secure x', 'not confirmed');
    Marker::write($context, []);

    new SetupRunner([])->applyHttpsConsequence($context);

    expect($context->answers->https)->toBeFalse()
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeFalse()
        ->and(new EnvFile($context->path('.env'))->get('MODULE_PASSKEYS'))->not->toBe('false')
        ->and(implode(' ', $context->notes))->toContain('herd secure '.$context->folderName());
});

it('quotes a folder name with a space in the commands of the HTTPS notes (RL-6, g2-05)', function (array $modules): void {
    $context = setupSandbox(prefix: 'my app ');
    $context->answers = Answers::fromArray(['modules' => $modules, 'https' => true], Detection::none(), $context->folderName());
    $context->results['herd-site'] = StepResult::manual('herd secure x', 'not confirmed');

    new SetupRunner([])->applyHttpsConsequence($context);

    expect(implode(' ', $context->notes))->toContain("herd secure '{$context->folderName()}'");
})->with(['with passkeys' => [['passkeys', 'impersonation']], 'without passkeys' => [['impersonation']]]);

it('names a re-run that turns https back on over the marker answers (OD-2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    $context->results['herd-site'] = StepResult::manual('herd secure x', 'not confirmed');
    Marker::write($context, []);

    new SetupRunner([])->applyHttpsConsequence($context);

    // A re-run takes every answer it is not given from the marker, which now says https: false.
    $installed = Marker::read($context)['answers'] ?? [];
    $note = implode(' ', $context->notes);
    preg_match("/skv:install --force --answers='(\{.*?\})'/", $note, $match);

    expect($installed['https'] ?? null)->toBeFalse()
        ->and($match)->toHaveKey(1)
        ->and(Answers::fromArray(json_decode($match[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR), Detection::none(), $context->folderName(), $installed)->https)->toBeTrue();
});

it('keeps https when the site was secured (OD-2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    $context->results['herd-site'] = StepResult::done(HerdSiteStep::SECURED.': Site secured with HTTPS');

    new SetupRunner([])->applyHttpsConsequence($context);

    expect($context->answers->https)->toBeTrue()->and($context->notes)->toBe([]);
});
