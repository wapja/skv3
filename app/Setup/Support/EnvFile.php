<?php

declare(strict_types=1);

namespace App\Setup\Support;

use Dotenv\Dotenv;
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

    /** @param  array<string, string|bool|int|null>  $variables */
    public function set(array $variables, bool $overwrite = true): void
    {
        if (! $this->exists()) {
            throw new RuntimeException("The environment file [{$this->path}] does not exist.");
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        copy($this->path, $temporary);

        try {
            Env::writeVariables(array_map(fn (string|bool|int|null $value): string => match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            }, $variables), $temporary, $overwrite);

            rename($temporary, $this->path);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
