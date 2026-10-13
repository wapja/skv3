<?php

declare(strict_types=1);

/** covers §5.3.3, §6.5 (DEC-29) */

use App\Setup\Steps\BrandingStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('keeps a valid accent and logo and writes the app name (§5.3.3 step 4)', function (): void {
    $context = setupSandbox();
    File::put($context->path('public/logo.svg'), '<svg/>');
    $env = new EnvFile($context->path('.env'));
    $env->set(['BRANDING_ACCENT' => 'indigo', 'BRANDING_LOGO' => 'logo.svg']);

    $result = new BrandingStep()->run($context);

    expect($result->status)->toBe('done')
        ->and($result->message)->toBe('')
        ->and($env->get('APP_NAME'))->toBe($context->answers->appName)
        ->and($env->get('BRANDING_ACCENT'))->toBe('indigo')
        ->and($env->get('BRANDING_LOGO'))->toBe('logo.svg');
});

it('clears an absolute or escaping logo and resets an unknown accent (DEC-29)', function (string $logo, ?string $file): void {
    $context = setupSandbox();
    // Each value names a file that exists, so only the guard the row is about can refuse it.
    if ($file !== null) {
        File::ensureDirectoryExists(dirname($context->path($file)));
        File::put($context->path($file), '<svg/>');
    }

    $env = new EnvFile($context->path('.env'));
    $env->set(['BRANDING_ACCENT' => 'chartreuse', 'BRANDING_LOGO' => $logo]);

    $result = new BrandingStep()->run($context);

    expect($env->get('BRANDING_ACCENT'))->toBe('neutral')
        ->and($env->get('BRANDING_LOGO'))->toBe('')
        ->and($result->message)->toContain(__('The logo must be a file under public/'))
        ->and($result->message)->toContain(__('Unknown accent :accent; using neutral.', ['accent' => 'chartreuse']));
})->with([
    'url' => ['https://x.svg', 'public/https:/x.svg'],
    'protocol-relative' => ['//logo.svg', 'public/logo.svg'],
    'escaping' => ['../logo.svg', 'logo.svg'],
    'missing file' => ['missing.svg', null],
    'not an image' => ['index.php', 'public/index.php'],
]);

it('sets the neutral accent without a note when none is set (§5.3.3 step 4)', function (): void {
    $context = setupSandbox();

    $result = new BrandingStep()->run($context);

    expect($result->message)->toBe('')
        ->and(new EnvFile($context->path('.env'))->get('BRANDING_ACCENT'))->toBe('neutral');
});

it('is complete once the branding values are valid (§5.3.1)', function (): void {
    $context = setupSandbox();
    $step = new BrandingStep;

    expect($step->isComplete($context))->toBeFalse();
    $step->run($context);
    expect($step->isComplete($context))->toBeTrue();

    new EnvFile($context->path('.env'))->set(['BRANDING_LOGO' => 'https://tracker.example/x.svg']);
    expect($step->isComplete($context))->toBeFalse();
});
