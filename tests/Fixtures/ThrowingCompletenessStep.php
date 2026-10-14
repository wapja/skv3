<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use RuntimeException;

/** A step whose completeness check throws, to pin which step a failure is reported under. */
final readonly class ThrowingCompletenessStep implements SetupStep
{
    public function key(): string
    {
        return 'throws-on-check';
    }

    public function label(): string
    {
        return 'Throws on check';
    }

    public function isComplete(SetupContext $context): bool
    {
        throw new RuntimeException('check failed');
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        return StepResult::done();
    }
}
