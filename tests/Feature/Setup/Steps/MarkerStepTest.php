<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Marker;
use App\Setup\Steps\MarkerStep;
use App\Setup\Support\Toolchain;
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
    File::put($context->path('storage/app/private/skv-setup-token'), 'token');
    File::put($context->path('storage/app/private/skv-setup.json'), '{}');

    new MarkerStep()->run($context);

    expect(Marker::exists($context))->toBeTrue()
        ->and(is_file($context->path('storage/app/private/skv-setup-token')))->toBeFalse()
        ->and(is_file($context->path('storage/app/private/skv-setup.json')))->toBeFalse();
});
