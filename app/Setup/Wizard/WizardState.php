<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Setup\Support\StateFile;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

/**
 * What the browser wizard knows between requests (RL-5): the pinned host, the answers (with the password's hash,
 * never the password), the detection snapshot begin() took (CD-15), the results of steps 1–8 and the notes. Step 9
 * deletes it, together with the per-step files of the detached steps in STEPS. A file without the snapshot (written
 * before M7b) is not a state this class reads: the runner deletes it and the wizard starts at the questions again.
 */
final class WizardState
{
    public const string PATH = 'storage/app/private/skv-setup.json';

    public const string STEPS = 'storage/app/private/skv-setup';

    /**
     * @param  array<string, StepResult>  $results
     * @param  list<string>  $notes
     */
    public function __construct(public string $host, public Answers $answers, public Detection $detection, public array $results = [], public array $notes = []) {}

    /** @throws JsonException|InvalidArgumentException when the file is not a state this class wrote */
    public static function load(string $basePath): ?self
    {
        $data = StateFile::readJson(rtrim($basePath, '/').'/'.self::PATH);

        if ($data === null) {
            return null;
        }

        if (! is_string($data['host'] ?? null) || ! is_array($data['answers'] ?? null) || ! is_array($data['detection'] ?? null)
            || ! is_array($data['results'] ?? null) || ! is_array($data['notes'] ?? null)) {
            throw new InvalidArgumentException('Setup state: unexpected shape.');
        }

        /** @var array<string, mixed> $answers */
        $answers = $data['answers'];

        return new self($data['host'], Answers::fromState($answers), Detection::fromArray($data['detection']), self::resultsFromState($data['results']), self::notesFromState($data['notes']));
    }

    public static function forget(string $basePath): void
    {
        $root = rtrim($basePath, '/');
        @unlink($root.'/'.self::PATH);
        self::removeDirectory($root.'/'.self::STEPS);
    }

    /** A symlink is removed, never followed (RL-5). */
    public static function removeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);
        } elseif (is_dir($directory)) {
            File::deleteDirectory($directory);
        }
    }

    /**
     * The stored results by step key; shared with HerdState, which stores them the same way.
     *
     * @param  array<array-key, mixed>  $results
     * @return array<string, StepResult>
     *
     * @throws InvalidArgumentException when a result is not a StepResult::toArray() object
     */
    public static function resultsFromState(array $results): array
    {
        $restored = [];

        foreach ($results as $key => $result) {
            if (! is_array($result)) {
                throw new InvalidArgumentException('Setup state: a result must be an object.');
            }

            /** @var array<string, mixed> $result */
            $restored[(string) $key] = StepResult::fromArray($result);
        }

        return $restored;
    }

    /**
     * @param  array<array-key, mixed>  $notes
     * @return list<string>
     */
    public static function notesFromState(array $notes): array
    {
        return array_values(array_map(fn (mixed $note): string => is_string($note) ? $note : throw new InvalidArgumentException('Setup state: a note must be a string.'), $notes));
    }

    public function save(string $basePath): void
    {
        StateFile::writeJson(rtrim($basePath, '/').'/'.self::PATH, [
            'host' => $this->host,
            'answers' => $this->answers->toState(),
            'detection' => $this->detection->toArray(),
            'results' => array_map(fn (StepResult $result): array => $result->toArray(), $this->results),
            'notes' => $this->notes,
        ]);
    }

    /** The setup context for the next step, on the snapshot begin() took (CD-15): no step request detects again. */
    public function context(string $basePath): SetupContext
    {
        $context = new SetupContext($basePath, $this->answers, $this->detection);
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
