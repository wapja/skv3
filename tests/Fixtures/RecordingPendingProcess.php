<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Process\InvokedProcess as InvokedProcessContract;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Process\PendingProcess;

/**
 * A PendingProcess that tells its RecordingProcessFactory about a stray command before the parent throws for it. The
 * condition is PendingProcess::run()'s and ::start()'s own (laravel/framework 13.x): no fake handler matches the
 * command line while the factory records and prevents strays.
 */
final class RecordingPendingProcess extends PendingProcess
{
    public function __construct(private readonly RecordingProcessFactory $recorder)
    {
        parent::__construct($recorder);
    }

    /** @param  array<array-key, string>|string|null  $command */
    public function run(array|string|null $command = null, ?callable $output = null): ProcessResultContract
    {
        $this->recordIfStray($command);

        return parent::run($command, $output);
    }

    /** @param  array<array-key, string>|string|null  $command */
    public function start(array|string|null $command = null, ?callable $output = null): InvokedProcessContract
    {
        $this->recordIfStray($command);

        return parent::start($command, $output);
    }

    /** @param  array<array-key, string>|string|null  $command */
    private function recordIfStray(array|string|null $command): void
    {
        $commandLine = $this->toSymfonyProcess($command ?? $this->command)->getCommandline();

        if ($this->fakeFor($commandLine) === null && $this->recorder->isRecording() && $this->recorder->preventingStrayProcesses()) {
            $this->recorder->recordStray($commandLine);
        }
    }
}
