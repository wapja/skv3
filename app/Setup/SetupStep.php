<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * One idempotent setup step (§5.3.1). The CLI (skv:install) and the browser wizard (M6b) drive the same
 * steps: isComplete() lets a re-run skip finished work, and run() reports what happened as a StepResult.
 */
interface SetupStep
{
    /** A stable id, used as the key of SetupContext::$results. */
    public function key(): string;

    /** The translated label shown while the step runs. */
    public function label(): string;

    /** The idempotency check: true when there is nothing left to do. */
    public function isComplete(SetupContext $context): bool;

    /** Whether the browser wizard runs the step detached (M6b); the CLI runs every step in-process. */
    public function isLong(): bool;

    public function run(SetupContext $context): StepResult;
}
