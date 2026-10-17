<?php

declare(strict_types=1);

/** covers §6.7 */

use App\Setup\Support\Toolchain;
use App\Support\Skv;
use Illuminate\Console\Signals;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;

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
    $order = [];
    $recorded = function (string $step, FakeProcessResult $result) use (&$order): Closure {
        return function () use ($step, $result, &$order): FakeProcessResult {
            $order[] = $step;

            return $result;
        };
    };
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'extra.skv.flux-pro' 'true' '--json'*" => $recorded('mark', Process::result('')),
        "*composer' 'config' 'repositories.flux-pro' 'composer' 'https://composer.fluxui.dev'*" => $recorded('repository', Process::result('')),
        "*composer' 'require' 'livewire/flux-pro'*" => $recorded('require', Process::result('Installed')),
    ]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->not->toContain('secret-key')->not->toContain('me@example.com');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'config repositories.flux-pro composer https://composer.fluxui.dev'));
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'require livewire/flux-pro'));
    // CD-13: the mark goes in before the require, because Composer's lock hash covers `extra`.
    expect($order)->toBe(['mark', 'repository', 'require']);
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
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'extra.skv.flux-pro'));
});

it('fails with the last line Composer printed when the require fails (RL-12)', function (): void {
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'repositories.flux-pro'*" => Process::result(''),
        "*composer' 'config' 'extra.skv.flux-pro' 'true' '--json'*" => Process::result(''),
        "*composer' 'config' '--unset' 'extra.skv'*" => Process::result(''),
        "*composer' 'config' '--unset' 'repositories.flux-pro'*" => Process::result(''),
        "*composer' 'require' 'livewire/flux-pro'*" => Process::result(errorOutput: "Updating dependencies\nYour requirements could not be resolved.\n", exitCode: 2),
    ]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Your requirements could not be resolved.')->not->toContain('Updating dependencies');

    // An install that did not finish must not mark composer.json as committing Flux Pro (CD-13).
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'config --unset extra.skv'));
    expect(is_file($this->probe))->toBeTrue();
});

/** Fakes every Composer call of skv:flux-pro; $results overrides a step by its name. */
$composerSteps = function (array $results = []): void {
    Process::fake([
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}'),
        "*composer' 'config' 'extra.skv.flux-pro' 'true' '--json'*" => $results['mark'] ?? Process::result(''),
        "*composer' 'config' 'repositories.flux-pro' 'composer' 'https://composer.fluxui.dev'*" => $results['repository'] ?? Process::result(''),
        "*composer' 'require' 'livewire/flux-pro'*" => $results['require'] ?? Process::result('Installed'),
        "*composer' 'config' '--unset' 'extra.skv'*" => $results['unset mark'] ?? Process::result(''),
        "*composer' 'config' '--unset' 'repositories.flux-pro'*" => $results['unset repository'] ?? Process::result(''),
    ]);
};

$ran = fn (string $command): Closure => fn ($process): bool => str_contains(implode(' ', (array) $process->command), $command);

it('undoes what a failed step added to composer.json and starts no later step (CD-13, L7b-72, L7b-73)', function (string $failing, array $undone, array $notRun) use ($composerSteps, $ran): void {
    $composerSteps([$failing => Process::result(errorOutput: "Composer refused.\n", exitCode: 1)]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Composer refused.');

    foreach ($undone as $command) {
        Process::assertRan($ran($command));
    }

    foreach ($notRun as $command) {
        Process::assertDidntRun($ran($command));
    }

    expect(is_file($this->probe))->toBeTrue();
})->with([
    'the mark' => ['mark', ['config --unset extra.skv'], ['config repositories.flux-pro', 'config --unset repositories.flux-pro', 'require livewire/flux-pro']],
    'the repository' => ['repository', ['config --unset repositories.flux-pro', 'config --unset extra.skv'], ['require livewire/flux-pro']],
    'the require' => ['require', ['config --unset repositories.flux-pro', 'config --unset extra.skv'], []],
]);

it('names the manual command for a key Composer would not remove (CD-13, L7b-72)', function () use ($composerSteps): void {
    $composerSteps([
        'require' => Process::result(errorOutput: "Your requirements could not be resolved.\n", exitCode: 2),
        'unset mark' => Process::result(errorOutput: "composer.json is not writable.\n", exitCode: 1),
    ]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1);
    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    expect($output)->toContain(__('Could not remove :key from composer.json. Run: composer config --unset :key', ['key' => 'extra.skv']))
        ->not->toContain(__('Could not remove :key from composer.json. Run: composer config --unset :key', ['key' => 'repositories.flux-pro']))
        ->toContain('Your requirements could not be resolved.');
});

it('undoes the mark and the repository and skips the require after Ctrl-C or SIGTERM (CD-13, L7b-81)', function () use ($composerSteps, $ran): void {
    // Signals are off under unit tests (ArtisanServiceProvider): turn them on, then signal this process while the
    // repository step "runs", as a terminal's Ctrl-C reaches the command while Composer works.
    // A handler of the test's own catches the signal should the command trap none, so a regression fails this test
    // instead of terminating the test process; the command's handler runs before it (Signals::register()).
    Signals::resolveAvailabilityUsing(fn (): bool => true);
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function (): void {});
    $composerSteps(['repository' => function (): FakeProcessResult {
        posix_kill(posix_getpid(), SIGTERM);

        return Process::result('');
    }]);

    try {
        $exit = Artisan::call('skv:flux-pro', ['--no-interaction' => true]);
    } finally {
        pcntl_signal(SIGTERM, SIG_DFL);
        Signals::resolveAvailabilityUsing(fn (): bool => false);
    }

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('Flux Pro was not installed: the command was interrupted.'));

    Process::assertRan($ran('config --unset repositories.flux-pro'));
    Process::assertRan($ran('config --unset extra.skv'));
    Process::assertDidntRun($ran('require livewire/flux-pro'));
})->skip(fn (): bool => ! extension_loaded('pcntl') || ! extension_loaded('posix'), 'needs pcntl and posix to trap and send a signal');

it('undoes the mark and the repository when a step throws instead of failing, as on a timeout or Ctrl-C (L7b-72, L7b-81)', function () use ($composerSteps, $ran): void {
    // A real Ctrl-C makes Composer re-raise the signal, so Symfony throws ProcessSignaledException instead of
    // returning a failed result; a timeout throws too. Only the timeout can be built in a fake.
    $composerSteps(['require' => fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['composer']), SymfonyTimeout::TYPE_GENERAL), Process::result(''))]);

    expect(Artisan::call('skv:flux-pro', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain(__('Composer could not install Flux Pro.'));

    Process::assertRan($ran('config --unset repositories.flux-pro'));
    Process::assertRan($ran('config --unset extra.skv'));
});
