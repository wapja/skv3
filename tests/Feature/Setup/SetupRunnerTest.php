<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Detection;
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
