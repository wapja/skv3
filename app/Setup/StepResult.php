<?php

declare(strict_types=1);

namespace App\Setup;

use InvalidArgumentException;

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

    /**
     * @param  array<string, mixed>  $data  a toArray() result
     *
     * @throws InvalidArgumentException on an unknown status or a malformed message
     */
    public static function fromArray(array $data): self
    {
        $message = is_string($data['message'] ?? null) ? $data['message'] : throw new InvalidArgumentException('Step result: message must be a string.');

        return match ($data['status'] ?? null) {
            'done' => self::done($message),
            'skipped' => self::skipped($message),
            'manual' => self::manual(is_string($data['command'] ?? null) ? $data['command'] : throw new InvalidArgumentException('Step result: a manual result needs a command.'), $message),
            default => throw new InvalidArgumentException('Step result: unknown status.'),
        };
    }

    public function isManual(): bool
    {
        return $this->status === 'manual';
    }

    /** The status in the active language, for the command line. */
    public function label(): string
    {
        return match ($this->status) {
            'done' => __('done'),
            'skipped' => __('skipped'),
            default => __('manual'),
        };
    }

    /** @return array{status: string, message: string, command: ?string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'message' => $this->message, 'command' => $this->command];
    }
}
