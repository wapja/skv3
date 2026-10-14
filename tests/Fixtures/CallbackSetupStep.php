<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use Closure;

/** A setup step that calls $during while it runs (something else changing state mid-step) and returns a fixed result. */
final readonly class CallbackSetupStep implements SetupStep
{
    /** @param  Closure(): void  $during */
    public function __construct(private string $stepKey, private Closure $during, private StepResult $result) {}

    public function key(): string
    {
        return $this->stepKey;
    }

    public function label(): string
    {
        return $this->stepKey;
    }

    public function isComplete(SetupContext $context): bool
    {
        return false;
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        ($this->during)();

        return $this->result;
    }
}
