<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use RuntimeException;

/** A doctor check whose run and fix both throw with a message that must never reach the report (RL-11). */
final readonly class ThrowingCheck implements Check
{
    public function key(): string
    {
        return 'throwing';
    }

    public function label(): string
    {
        return 'Throwing';
    }

    public function run(DoctorContext $context): CheckResult
    {
        throw new RuntimeException('secret-binding');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        throw new RuntimeException('secret-binding');
    }
}
