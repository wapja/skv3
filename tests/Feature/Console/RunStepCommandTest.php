<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupRunner;
use App\Setup\StepResult;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Support\StateFile;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Fixtures\CallbackSetupStep;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('runs one step from the wizard state and writes its result (RL-4)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    new WizardState('localhost', Answers::defaults(Detection::none(), $context->folderName()))->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));
    $result = $context->path(WizardState::STEPS.'/git.json');
    Process::fake(["*git' 'init'*" => Process::result(''), "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result('')]);

    $exitCode = Artisan::call('skv:run-step', ['step' => 'git', '--result' => $result]);

    expect($exitCode)->toBe(0)
        ->and(StateFile::readJson($result)['status'] ?? null)->toBeIn(['done', 'skipped', 'manual']);
});

it('refuses a result path outside storage/app/private and an unknown step (RL-5)', function (string $step, string $where): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    new WizardState('localhost', Answers::defaults(Detection::none(), $context->folderName()))->save($context->basePath);
    File::ensureDirectoryExists($context->path(WizardState::STEPS));

    expect(Artisan::call('skv:run-step', ['step' => $step, '--result' => $context->path($where)]))->toBe(1)
        ->and(file_exists($context->path($where)))->toBeFalse();
})->with([
    'outside' => ['git', 'public/result.json'],
    'unknown step' => ['nope', WizardState::STEPS.'/nope.json'],
]);

it('runs the Herd step from the Herd state, applies the HTTPS consequence and rewrites the marker (RL-6, OD-2)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    Marker::write($context, []);
    HerdState::begin($context->basePath, $context, 'localhost');

    $exitCode = Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]);
    $state = HerdState::load($context->basePath);

    expect($exitCode)->toBe(0)
        ->and($state?->result?->status)->toBe('skipped')
        ->and($state?->answers->https)->toBeFalse()
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeFalse();
});

it('leaves the Herd state and the marker alone when the page moved on while Herd ran (RL-6)', function (bool $used): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    Marker::write($context, []);
    [$state] = HerdState::begin($context->basePath, $context, 'localhost');

    if ($used) {
        HerdState::forget($context->basePath);
    } else {
        $state->result = StepResult::manual('herd secure '.$context->folderName(), 'Continued without HTTPS.');
        $state->save($context->basePath);
    }

    $marker = (string) file_get_contents($context->path(Marker::FILE));

    expect(Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]))->toBe(0)
        ->and(file_get_contents($context->path(Marker::FILE)))->toBe($marker)
        ->and(HerdState::load($context->basePath)?->result?->message)->toBe($used ? null : 'Continued without HTTPS.');
})->with(['login used the secret' => [true], 'continued without HTTPS' => [false]]);

it('never writes the Herd state back when the page moved on during the Herd step (RL-6)', function (bool $used): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    Marker::write($context, []);
    [$state] = HerdState::begin($context->basePath, $context, 'localhost');
    $marker = (string) file_get_contents($context->path(Marker::FILE));
    $movedOn = function () use ($used, $state, $context): void {
        if ($used) {
            HerdState::forget($context->basePath);
        } else {
            $state->result = StepResult::manual('herd secure '.$context->folderName(), 'Continued without HTTPS.');
            $state->save($context->basePath);
        }
    };
    app()->instance(SetupRunner::class, new SetupRunner([
        new CallbackSetupStep('herd-site', $movedOn, StepResult::done(HerdSiteStep::SECURED.': late')),
    ]));

    expect(Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]))->toBe(0)
        ->and(file_get_contents($context->path(Marker::FILE)))->toBe($marker);

    // A state written back would make the used-up secret valid again, or overwrite the user's choice.
    $after = HerdState::load($context->basePath);
    expect($used ? $after : $after?->result?->message)->toBe($used ? null : 'Continued without HTTPS.');
})->with(['login used the secret' => [true], 'continued without HTTPS' => [false]]);

it('leaves a Herd state that another finish replaced while Herd ran (RL-6)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $context->answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], Detection::none(), $context->folderName());
    Marker::write($context, []);
    HerdState::begin($context->basePath, $context, 'localhost');
    $replaced = null;
    app()->instance(SetupRunner::class, new SetupRunner([
        new CallbackSetupStep('herd-site', function () use ($context, &$replaced): void {
            [$replaced] = HerdState::begin($context->basePath, $context, 'localhost');
        }, StepResult::done(HerdSiteStep::SECURED.': late')),
    ]));

    expect(Artisan::call('skv:run-step', ['step' => 'herd-site', '--result' => $context->path(HerdState::PATH)]))->toBe(0);

    $after = HerdState::load($context->basePath);
    expect($after?->secretHash)->toBe($replaced?->secretHash)
        ->and($after?->result)->toBeNull();
});
