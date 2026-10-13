<?php

declare(strict_types=1);

namespace App\Setup;

/** Where setup writes and what it was told (RL-2): steps resolve every file through path(), never base_path(). */
final class SetupContext
{
    /** @var array<string, StepResult> results by step key, in run order */
    public array $results = [];

    /** @var list<string> notes for the summary that belong to no single step (the passkeys consequence, RL-7) */
    public array $notes = [];

    public function __construct(
        public readonly string $basePath,
        public Answers $answers,
        public readonly Detection $detection,
        public bool $force = false,
    ) {}

    public static function forApplication(Answers $answers, Detection $detection, bool $force = false): self
    {
        return new self(base_path(), $answers, $detection, $force);
    }

    public function path(string $relative = ''): string
    {
        return rtrim($this->basePath, '/').($relative === '' ? '' : '/'.ltrim($relative, '/'));
    }

    public function folderName(): string
    {
        return basename($this->basePath);
    }
}
