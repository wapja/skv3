<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use ArrayObject;

/** A setup step that appends its key to $ran when it runs and returns a fixed result. */
final readonly class RecordingSetupStep implements SetupStep
{
    /** @param  ArrayObject<int, string>  $ran */
    public function __construct(private string $stepKey, private ArrayObject $ran, private StepResult $result, private bool $complete) {}

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
        return $this->complete;
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        $this->ran->append($this->stepKey);

        return $this->result;
    }
}
