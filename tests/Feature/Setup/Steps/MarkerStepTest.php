<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Marker;
use App\Setup\SetupToken;
use App\Setup\Steps\MarkerStep;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('writes the marker and removes the setup token and state files (§5.3.3 step 9)', function (): void {
    $context = setupSandbox();
    File::put($context->path(SetupToken::PATH), 'token');
    File::put($context->path(WizardState::PATH), '{}');

    new MarkerStep()->run($context);

    expect(Marker::exists($context))->toBeTrue()
        ->and(is_file($context->path(SetupToken::PATH)))->toBeFalse()
        ->and(is_file($context->path(WizardState::PATH)))->toBeFalse();
});

it('deletes the wizard state and its step files, and never follows a symlinked directory (RL-5)', function (): void {
    $context = setupSandbox();
    $outside = $context->path('outside');
    File::ensureDirectoryExists($outside);
    file_put_contents($outside.'/keep', 'x');
    symlink($outside, $context->path(WizardState::STEPS));
    new WizardState('localhost', $context->answers, $context->detection)->save($context->basePath);

    new MarkerStep()->run($context);

    expect(file_exists($context->path(WizardState::PATH)))->toBeFalse()
        ->and(is_link($context->path(WizardState::STEPS)))->toBeFalse()
        ->and($outside.'/keep')->toBeFile();
});
