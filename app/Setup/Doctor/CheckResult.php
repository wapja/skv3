<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

use App\Enums\CheckStatus;

/** What a check found (§5.4): a status, a message and, when not passing, the exact fix command or instruction. */
final readonly class CheckResult
{
    private function __construct(
        public CheckStatus $status,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function pass(string $message): self
    {
        return new self(CheckStatus::Pass, $message);
    }

    public static function warn(string $message, ?string $fix = null): self
    {
        return new self(CheckStatus::Warn, $message, $fix);
    }

    public static function fail(string $message, ?string $fix = null): self
    {
        return new self(CheckStatus::Fail, $message, $fix);
    }

    public static function skipped(string $reason): self
    {
        return new self(CheckStatus::Skipped, $reason);
    }
}
