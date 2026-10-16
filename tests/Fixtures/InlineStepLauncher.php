<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\Wizard\StepLauncher;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/** Runs a "detached" step in-process and at once, so tests never spawn a process (RL-4); it writes no pid (RL-8). */
final class InlineStepLauncher implements StepLauncher
{
    /** @var list<string> */
    public array $started = [];

    public bool $refuses = false;

    /** Thrown by start() instead of running the step, as an unexpected failure would be. */
    public ?Throwable $throws = null;

    public function start(string $basePath, string $key, string $resultFile, string $logFile, string $exitFile, ?string $pidFile = null): bool
    {
        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        if ($this->refuses) {
            return false;
        }

        $this->started[] = $key;
        $exitCode = Artisan::call('skv:run-step', ['step' => $key, '--result' => $resultFile, '--no-interaction' => true]);
        @mkdir(dirname($exitFile), 0700, true);
        file_put_contents($exitFile, (string) $exitCode);

        return true;
    }
}
