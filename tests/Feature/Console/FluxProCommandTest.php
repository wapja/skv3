<?php

declare(strict_types=1);

/** covers §6.7 */

use App\Setup\Support\Toolchain;
use App\Support\Skv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    // CI may have Pro installed before composer check: force the free branch unless a test says otherwise.
    Skv::useFluxPro(false);
    $this->probe = config('view.compiled').'/flux-pro-probe-'.getmypid().'.php';
    File::put($this->probe, '<?php');
});

afterEach(function (): void {
    Skv::useFluxPro(null);
    File::delete($this->probe);
});

it('adds the repository, requires Flux Pro and clears compiled views (§6.7, DEC-29)', function (): void {
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'repositories.flux-pro' 'composer' 'https://composer.fluxui.dev'*" => Process::result(''),
        "*composer' 'require' 'livewire/flux-pro'*" => Process::result('Installed'),
    ]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->not->toContain('secret-key')->not->toContain('me@example.com');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'config repositories.flux-pro composer https://composer.fluxui.dev'));
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require livewire/flux-pro'));
    expect(is_file($this->probe))->toBeFalse();
});

it('only clears compiled views when Flux Pro is already installed (DEC-29)', function (): void {
    Skv::useFluxPro(true);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain(__('Flux Pro is already installed.'));

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require livewire/flux-pro'));
    expect(is_file($this->probe))->toBeFalse();
});

it('stops with the README one-liner when no credentials are configured (RL-12)', function (): void {
    Process::fake(["*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1)]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('composer config --global http-basic.composer.fluxui.dev');

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require livewire/flux-pro'));
});

it('fails with the last line Composer printed when the require fails (RL-12)', function (): void {
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'repositories.flux-pro'*" => Process::result(''),
        "*composer' 'require' 'livewire/flux-pro'*" => Process::result(errorOutput: "Updating dependencies\nYour requirements could not be resolved.\n", exitCode: 2),
    ]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Your requirements could not be resolved.')->not->toContain('Updating dependencies');

    expect(is_file($this->probe))->toBeTrue();
});
