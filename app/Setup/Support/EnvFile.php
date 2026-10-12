<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use Dotenv\Parser\Parser;
use Dotenv\Parser\Value;
use Illuminate\Support\Env;
use RuntimeException;

/** Reads and atomically writes one .env file (§5.3.3 step 1): values never leave this class except through get(). */
final readonly class EnvFile
{
    public function __construct(private string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $values = Dotenv::parse((string) file_get_contents($this->path));

        return array_key_exists($key, $values) ? (string) $values[$key] : null;
    }

    /**
     * Writes through a private temp copy that must parse and hold exactly the requested values before it replaces
     * the file; the file keeps its mode. Errors name keys only, never values.
     *
     * @param  array<string, string|bool|int|null>  $variables
     *
     * @throws RuntimeException when the file is missing, a key or value is not allowed, or the result would not read back
     */
    public function set(array $variables, bool $overwrite = true): void
    {
        if (! $this->exists()) {
            throw new RuntimeException("The environment file [{$this->path}] does not exist.");
        }

        $values = array_map(fn (string|bool|int|null $value): string => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => (string) $value,
        }, $variables);

        foreach ($values as $key => $value) {
            if (preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) !== 1) {
                throw new RuntimeException('An environment variable name must be upper case letters, digits and underscores.');
            }

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new RuntimeException("The value for [{$key}] contains a line break or a NUL byte.");
            }
        }

        $original = (string) file_get_contents($this->path);
        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        try {
            $this->createPrivately($temporary, $original);
            Env::writeVariables($values, $temporary, $overwrite);
            $this->assertReadsBack($original, (string) file_get_contents($temporary), $values, $overwrite);

            chmod($temporary, fileperms($this->path) & 0777);
            rename($temporary, $this->path);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Creates the temp file owner-only before any content goes in, so no secret is ever world-readable. */
    private function createPrivately(string $temporary, string $contents): void
    {
        $handle = fopen($temporary, 'x');

        if ($handle === false) {
            throw new RuntimeException("Could not create a temporary copy of [{$this->path}].");
        }

        try {
            chmod($temporary, 0600);
            fwrite($handle, $contents);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Every requested key holds exactly its new value (or, without overwrite, its old one), and no other key appeared
     * or changed. Values are compared before ${VAR} interpolation, so a deliberate reference stays allowed.
     *
     * @param  array<string, string>  $values
     */
    private function assertReadsBack(string $original, string $written, array $values, bool $overwrite): void
    {
        $keys = implode(', ', array_keys($values));

        try {
            $before = $this->literalValues($original);
            $after = $this->literalValues($written);
        } catch (ExceptionInterface) {
            throw new RuntimeException("Writing [{$keys}] would leave [{$this->path}] unreadable; the file was not changed.");
        }

        foreach ($values as $key => $value) {
            $keptOld = ! $overwrite && array_key_exists($key, $before) && ($after[$key] ?? null) === $before[$key];

            if (($after[$key] ?? null) !== $value && ! $keptOld) {
                throw new RuntimeException("The value for [{$key}] would not read back unchanged; [{$this->path}] was not changed.");
            }
        }

        if (array_diff_key($after, $values) !== array_diff_key($before, $values)) {
            throw new RuntimeException("Writing [{$keys}] would change other variables in [{$this->path}]; the file was not changed.");
        }
    }

    /**
     * @return array<string, ?string> each variable's literal value (before interpolation); the last line wins, as in Dotenv::parse()
     *
     * @throws ExceptionInterface
     */
    private function literalValues(string $contents): array
    {
        $values = [];

        foreach (new Parser()->parse($contents) as $entry) {
            $values[$entry->getName()] = $entry->getValue()->map(fn (Value $value): string => $value->getChars())->getOrElse(null);
        }

        return $values;
    }
}
