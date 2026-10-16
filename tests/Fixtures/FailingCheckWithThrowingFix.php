<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use RuntimeException;

/** A doctor check that fails with its own message and fix command, and whose repair throws (A9, L-11). */
final readonly class FailingCheckWithThrowingFix implements Check
{
    public function key(): string
    {
        return 'failing';
    }

    public function label(): string
    {
        return 'Failing';
    }

    public function run(DoctorContext $context): CheckResult
    {
        return CheckResult::fail('first', 'first-fix');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        throw new RuntimeException('secret-binding');
    }
}
