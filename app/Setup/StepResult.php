<?php

declare(strict_types=1);

namespace App\Setup;

/** What a step did (§5.3.1): done, skipped, or a command the user runs themselves. */
final readonly class StepResult
{
    private function __construct(
        public string $status,
        public string $message,
        public ?string $command = null,
    ) {}

    public static function done(string $message = ''): self
    {
        return new self('done', $message);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', $reason);
    }

    public static function manual(string $command, string $reason): self
    {
        return new self('manual', $reason, $command);
    }

    public function isManual(): bool
    {
        return $this->status === 'manual';
    }
}
