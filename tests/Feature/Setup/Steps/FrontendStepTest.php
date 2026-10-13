<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Steps\FrontendStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $home = storage_path('framework/testing/setup/home-'.Str::uuid());
    $bin = $home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin';
    File::ensureDirectoryExists($bin);

    foreach (['node', 'npm', 'npx'] as $binary) {
        File::put("{$bin}/{$binary}", "#!/bin/sh\n");
        chmod("{$bin}/{$binary}", 0755);
    }

    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($home));
    app()->instance(Toolchain::class, new Toolchain($home));
});

it('builds the assets when the manifest is missing (§5.3.3 step 5)', function (): void {
    $context = setupSandbox();
    Process::fake([
        "*node' '-v'*" => Process::result("v22.23.2\n"),
        "*npm' 'ci'*" => Process::result('added 120 packages'),
        "*npm' 'run' 'build'*" => Process::result('built in 1s'),
    ]);

    expect(new FrontendStep()->run($context)->status)->toBe('done');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'npm run build') || str_contains(implode(' ', (array) $process->command), 'run build'));
});

it('skips the build when the manifest is current (§5.3.3 step 5)', function (): void {
    $context = setupSandbox();
    File::ensureDirectoryExists($context->path('public/build'));
    File::put($context->path('public/build/manifest.json'), '{}');
    Process::fake(["*node' '-v'*" => Process::result("v22.23.2\n")]);

    expect(new FrontendStep()->isComplete($context))->toBeTrue();
});

it('rebuilds when a source file is newer than the manifest (§5.3.3 step 5)', function (): void {
    $context = setupSandbox();
    File::ensureDirectoryExists($context->path('public/build'));
    File::put($context->path('public/build/manifest.json'), '{}');
    touch($context->path('public/build/manifest.json'), time() - 60);
    File::ensureDirectoryExists($context->path('resources/js'));
    File::put($context->path('resources/js/app.js'), '');

    expect(new FrontendStep()->isComplete($context))->toBeFalse();
});

it('hands the user a command when Node is too old or the build fails (§5.3.3 step 5)', function (string $node, int $buildExit): void {
    $context = setupSandbox();
    Process::fake([
        "*node' '-v'*" => Process::result($node),
        "*npm' 'ci'*" => Process::result('', exitCode: $buildExit),
        "*npm' 'run' 'build'*" => Process::result('', exitCode: $buildExit),
    ]);

    $result = new FrontendStep()->run($context);

    expect($result->isManual())->toBeTrue()->and($result->command)->toBe('npm ci && npm run build');
})->with([
    'node 18' => ["v18.20.0\n", 0],
    'build fails' => ["v22.23.2\n", 1],
]);

it('skips the build when the installer was asked not to use Node (§5.2)', function (): void {
    $context = setupSandbox();
    Process::fake(["*node' '-v'*" => Process::result("v22.23.2\n")]);
    putenv('LARAVEL_INSTALLER_NO_NODE=1');

    try {
        $result = new FrontendStep()->run($context);
    } finally {
        putenv('LARAVEL_INSTALLER_NO_NODE');
    }

    expect($result->status)->toBe('skipped');
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'npm'));
});

it('installs Chromium when Playwright was chosen (§5.3.3 step 5)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['playwright' => true], $context->detection, $context->folderName());
    Process::fake([
        "*node' '-v'*" => Process::result("v22.23.2\n"),
        "*npm'*" => Process::result(''),
        "*npx' 'playwright' 'install' 'chromium'*" => Process::result(''),
    ]);

    new FrontendStep()->run($context);

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'playwright install chromium'));
});

it('accepts exactly the supported Node versions', function (string $version, bool $ok): void {
    expect(FrontendStep::nodeSatisfies($version))->toBe($ok);
})->with([
    ['v20.19.0', true], ['v20.18.9', false], ['v21.7.0', false], ['v22.12.0', true], ['v22.11.0', false], ['v24.1.0', true],
]);
