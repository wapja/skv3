<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\HerdManifestStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('writes herd.yml with the name, PHP 8.4, the HTTPS answer and PostgreSQL 18 on pgsql (§5.3.3 step 3)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => [], 'https' => false], Detection::none(), $context->folderName());

    new HerdManifestStep()->run($context);
    $manifest = Yaml::parseFile($context->path('herd.yml'));

    expect($manifest['name'])->toBe($context->folderName())
        ->and($manifest['php'])->toBe('8.4')
        ->and($manifest['secured'])->toBeFalse()
        ->and($manifest['services']['postgresql']['version'] ?? null)->toStartWith('18')
        ->and($manifest['services']['postgresql']['port'] ?? null)->toBe('${DB_PORT}');
});

it('lists no services on SQLite', function (): void {
    $context = setupSandbox();

    new HerdManifestStep()->run($context);

    expect(Yaml::parseFile($context->path('herd.yml')))->not->toHaveKey('services');
});

it('is complete once herd.yml matches the answers (§5.3.1)', function (): void {
    $context = setupSandbox();
    $step = new HerdManifestStep;

    expect($step->isComplete($context))->toBeFalse();
    $step->run($context);
    expect($step->isComplete($context))->toBeTrue();

    $context->answers = $context->answers->withHttps(false)->withModules([]);
    expect($step->isComplete($context))->toBeFalse();
});

it('keeps the top-level keys it does not write, such as a Forge link (§5.3.3 step 3, DEC-30)', function (): void {
    $context = setupSandbox();
    file_put_contents($context->path('herd.yml'), "name: old-name\nphp: '8.3'\nsecured: true\naliases:\n- legacy\nintegrations:\n  forge:\n    server-id: 123\n    site-id: 456\n");
    $step = new HerdManifestStep;

    $step->run($context);
    $manifest = Yaml::parseFile($context->path('herd.yml'));

    expect($manifest['name'])->toBe($context->folderName())
        ->and($manifest['php'])->toBe('8.4')
        ->and($manifest['aliases'])->toBe(['legacy'])
        ->and($manifest['integrations']['forge'] ?? null)->toBe(['server-id' => 123, 'site-id' => 456])
        ->and($step->isComplete($context))->toBeTrue();
});
