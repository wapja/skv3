<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Marker;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('records the answers without secrets and the summary in the marker (§5.3.3 step 9, RL-14)', function (): void {
    $context = setupSandbox();

    expect(Marker::exists($context))->toBeFalse();

    Marker::write($context, ['urls' => ['http://localhost']]);

    $marker = Marker::read($context);

    expect(Marker::exists($context))->toBeTrue()
        ->and($marker)->not->toBeNull()
        ->and($marker['start_with'] ?? null)->toBe('demo')
        ->and($marker['answers'] ?? [])->not->toHaveKey('super_admin_password')
        ->and($marker['summary'] ?? null)->toBe(['urls' => ['http://localhost']])
        ->and(is_file($context->path(Marker::FILE)))->toBeTrue();
});
