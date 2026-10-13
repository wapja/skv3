<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enums\CheckStatus;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** A doctor check that always reports the given status and has no fix. */
final readonly class FixedStatusCheck implements Check
{
    public function __construct(private CheckStatus $status, private string $checkKey) {}

    public function key(): string
    {
        return $this->checkKey;
    }

    public function label(): string
    {
        return 'Probe';
    }

    public function run(DoctorContext $context): CheckResult
    {
        return match ($this->status) {
            CheckStatus::Fail => CheckResult::fail('broken', 'repair'),
            CheckStatus::Warn => CheckResult::warn('odd'),
            default => CheckResult::pass('fine'),
        };
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
