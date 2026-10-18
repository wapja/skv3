<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;

/**
 * The Process factory of every RL-12 test (tests/Pest.php), the runtime half of the static rule in
 * tests/Arch/KitRulesTest.php. It notes every stray command before PendingProcess throws, so a caller's
 * `catch (Throwable)` cannot hide one (L7b-78), and it names the fake patterns that match any command, whatever form
 * the test wrote them in (L7b-77). Both read laravel/framework 13.x internals: the protected Factory::$fakeHandlers
 * (pattern => handler; a list value and a closure are stored under '*') and PendingProcess's stray branch, which
 * RecordingPendingProcess repeats.
 */
final class RecordingProcessFactory extends Factory
{
    /** @var list<string> */
    private array $strays = [];

    /** The factory behind the Process facade; a test outside the RL-12 directories has none. */
    public static function current(): self
    {
        $factory = Process::getFacadeRoot();

        return $factory instanceof self ? $factory : throw new LogicException('The Process facade has no RecordingProcessFactory (RL-12 binds it).');
    }

    public function newPendingProcess(): PendingProcess
    {
        return new RecordingPendingProcess($this)->withFakeHandlers($this->fakeHandlers);
    }

    public function recordStray(string $command): void
    {
        $this->strays[] = $command;
    }

    /** @return list<string> the command lines that ran into the stray-process guard, in order */
    public function strays(): array
    {
        return $this->strays;
    }

    public function forgetStrays(): void
    {
        $this->strays = [];
    }

    /** @return list<string> the fake patterns that also match a command no test names */
    public function catchAllPatterns(): array
    {
        // Symfony's single-quoted command line of an argv nobody fakes by name.
        $unnamed = "'/".Str::uuid()."' '--".Str::uuid()."'";

        return array_values(array_filter(
            array_map(strval(...), array_keys($this->fakeHandlers)),
            fn (string $pattern): bool => Str::is($pattern, $unnamed),
        ));
    }
}
