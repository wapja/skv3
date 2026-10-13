<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;

/** §5.3.3 step 11: always runs last; skv:install prints App\Setup\Summary::build() after it. */
final readonly class SummaryStep implements SetupStep
{
    public function key(): string
    {
        return 'summary';
    }

    public function label(): string
    {
        return __('Summary');
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
        return StepResult::done();
    }
}
