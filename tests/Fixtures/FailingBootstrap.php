<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\Support\BootstrapRunner;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\FakeProcessResult;

/** A bootstrap whose migrate fails with output that must not reach the screen (RL-13). */
final readonly class FailingBootstrap implements BootstrapRunner
{
    public function run(): array
    {
        throw new ProcessFailedException(new FakeProcessResult(command: 'php artisan migrate', exitCode: 1, output: "migrate failed\nsecret output"));
    }
}
