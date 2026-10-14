<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Setup\Support\StateFile;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;

/**
 * The Herd stage after the marker (RL-6): the sha256 of the one-time secret only the wizard's browser holds, the
 * pinned host, the public answers, the results and notes of steps 1–9, when the stage started, and once the detached
 * Herd step has finished its result. The file holds no password hash (RL-5): the super admin exists by now.
 */
final class HerdState
{
    public const string PATH = 'storage/app/private/skv-herd.json';

    public const string EXIT = 'storage/app/private/skv-herd.exit';

    public const string LOG = 'storage/logs/skv-herd.log';

    /** Seconds the secret stays valid. */
    public const int LIFETIME = 1800;

    /** Seconds the page polls before the Herd step's answer is overdue and the manual command is offered (§5.3.4). */
    public const int POLL_SECONDS = 60;

    /**
     * @param  array<string, StepResult>  $results
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $secretHash,
        public string $host,
        public Answers $answers,
        public array $results,
        public array $notes,
        public int $startedAt,
        public ?StepResult $result = null,
    ) {}

    /**
     * Starts the Herd stage for the context of a finished step 9 and saves it.
     *
     * @return array{0: self, 1: string} the state and the plain secret, which only the page receives
     */
    public static function begin(string $basePath, SetupContext $context, string $host): array
    {
        $secret = bin2hex(random_bytes(32));
        $state = new self(
            secretHash: hash('sha256', $secret),
            host: $host,
            answers: Answers::fromState([...$context->answers->toPublicArray(), 'super_admin_password_hash' => null]),
            results: $context->results,
            notes: $context->notes,
            startedAt: Date::now()->getTimestamp(),
        );
        $state->save($basePath);

        return [$state, $secret];
    }

    /** @throws JsonException|InvalidArgumentException when the file is not a state this class wrote */
    public static function load(string $basePath): ?self
    {
        $data = StateFile::readJson(rtrim($basePath, '/').'/'.self::PATH);

        if ($data === null) {
            return null;
        }

        if (! is_string($data['secret_hash'] ?? null) || ! is_string($data['host'] ?? null) || ! is_array($data['answers'] ?? null)
            || ! is_array($data['results'] ?? null) || ! is_array($data['notes'] ?? null) || ! is_int($data['started_at'] ?? null)
            || (($data['result'] ?? null) !== null && ! is_array($data['result']))) {
            throw new InvalidArgumentException('Herd state: unexpected shape.');
        }

        /** @var array<string, mixed> $answers */
        $answers = $data['answers'];
        /** @var array<string, mixed>|null $result */
        $result = $data['result'] ?? null;

        return new self(
            secretHash: $data['secret_hash'],
            host: $data['host'],
            answers: Answers::fromState($answers),
            results: WizardState::resultsFromState($data['results']),
            notes: WizardState::notesFromState($data['notes']),
            startedAt: $data['started_at'],
            result: $result === null ? null : StepResult::fromArray($result),
        );
    }

    /** Removes the state and the exit file; the log stays for the developer. */
    public static function forget(string $basePath): void
    {
        $root = rtrim($basePath, '/');
        @unlink($root.'/'.self::PATH);
        @unlink($root.'/'.self::EXIT);
    }

    public function save(string $basePath): void
    {
        StateFile::writeJson(rtrim($basePath, '/').'/'.self::PATH, [
            'secret_hash' => $this->secretHash,
            'host' => $this->host,
            // Never a password hash, whatever context the answers were absorbed from (RL-5).
            'answers' => [...$this->answers->toPublicArray(), 'super_admin_password_hash' => null],
            'results' => array_map(fn (StepResult $result): array => $result->toArray(), $this->results),
            'notes' => $this->notes,
            'started_at' => $this->startedAt,
            'result' => $this->result?->toArray(),
        ]);
    }

    public function matches(string $secret): bool
    {
        return hash_equals($this->secretHash, hash('sha256', $secret));
    }

    public function isFresh(): bool
    {
        return Date::now()->getTimestamp() - $this->startedAt <= self::LIFETIME;
    }

    public function context(string $basePath, Detection $detection): SetupContext
    {
        $context = new SetupContext($basePath, $this->answers, $detection);
        $context->results = $this->results;
        $context->notes = $this->notes;

        return $context;
    }

    public function absorb(SetupContext $context): void
    {
        $this->answers = $context->answers;
        $this->results = $context->results;
        $this->notes = $context->notes;
    }
}
