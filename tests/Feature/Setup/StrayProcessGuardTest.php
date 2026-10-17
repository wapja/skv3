<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Fixtures\RecordingProcessFactory;

it('refuses a process that no fake names, also in a file without its own fake (RL-12)', function (): void {
    expect(fn () => Process::run(['true']))->toThrow(RuntimeException::class, 'without a matching fake');

    // The stray is the point of this test: the RL-12 afterEach would fail it otherwise (L7b-78).
    expect(RecordingProcessFactory::current()->strays())->toBe(["'true'"]);
    RecordingProcessFactory::current()->forgetStrays();
});

it('records every stray before it throws, so a caller that catches the exception cannot hide it (L7b-78)', function (): void {
    $factory = new RecordingProcessFactory;
    $factory->preventStrayProcesses();
    $factory->fake(["*git' 'init'*" => Process::result('')]);
    $factory->newPendingProcess()->run(['git', 'init']);

    foreach ([fn () => $factory->newPendingProcess()->run(['herd', 'links']), fn () => $factory->newPendingProcess()->start(['npm', 'ci'])] as $stray) {
        try {
            $stray();
        } catch (RuntimeException) {
            // Detector, HerdTld, HerdSiteStep::manualCommand(), DatabaseStep and the Doctor catch Throwable like this.
        }
    }

    expect($factory->strays())->toBe(["'herd' 'links'", "'npm' 'ci'"]);
});

it('names a fake that matches every command, whatever form the test wrote it in (L7b-77)', function (Closure $fakes): void {
    $factory = new RecordingProcessFactory;
    $factory->fake($fakes());

    expect($factory->catchAllPatterns())->not->toBe([]);
})->with([
    'a double star' => [fn (): array => ['**' => Process::result('')]],
    'a single star' => [fn (): array => ['*' => Process::result('')]],
    'a list value after a named key' => [fn (): array => ["*git' 'init'*" => Process::result(''), Process::result('')]],
    'any quoted command' => [fn (): array => ["*'*" => Process::result('')]],
    'a closure' => [fn (): Closure => fn () => Process::result('')],
]);

it('accepts fakes that each name a command (L7b-77)', function (): void {
    $factory = new RecordingProcessFactory;
    $factory->fake([
        "*herd' 'links'*" => Process::result(''),
        "*git' 'config' '--get' 'core.hooksPath'*" => Process::result('.githooks'),
        "*'tld'*" => Process::result('test'),
    ]);

    expect($factory->catchAllPatterns())->toBe([]);
});

it('binds the recording factory in every RL-12 test (L7b-77, L7b-78)', function (): void {
    expect(Process::getFacadeRoot())->toBeInstanceOf(RecordingProcessFactory::class)
        ->and(RecordingProcessFactory::current()->isRecording())->toBeTrue()
        ->and(RecordingProcessFactory::current()->preventingStrayProcesses())->toBeTrue();
});

it('checks every RL-12 test for catch-all fakes and strays once it ran (L7b-77, L7b-78)', function (): void {
    $pest = (string) file_get_contents(base_path('tests/Pest.php'));
    $hooks = Str::between($pest, 'pest()->beforeEach(function (): void {'."\n".'    Process::swap(new RecordingProcessFactory);', "->in('Feature/Setup'");

    expect($hooks)->toContain('})->afterEach(function (): void {')
        ->toContain('$factory = RecordingProcessFactory::current();')
        ->toContain('expect($factory->catchAllPatterns())->toBe([]')
        ->toContain('->and($factory->strays())->toBe([]');
});
