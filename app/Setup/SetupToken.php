<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\Support\StateFile;

/** The one-time setup token (§5.2 point 5, §5.3.4): written by skv:bootstrap, deleted by step 9 (the marker). */
final readonly class SetupToken
{
    public const string PATH = 'storage/app/private/skv-setup-token';

    public function __construct(private string $basePath) {}

    public function path(): string
    {
        return rtrim($this->basePath, '/').'/'.self::PATH;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** Creates the token once, while setup has not finished: 32 random bytes as 64 hex characters, mode 0600. */
    public function ensure(): bool
    {
        if ($this->exists() || is_file(rtrim($this->basePath, '/').'/'.Marker::FILE)) {
            return false;
        }

        StateFile::write($this->path(), bin2hex(random_bytes(32)));

        return true;
    }

    public function value(): ?string
    {
        $value = $this->exists() ? trim((string) file_get_contents($this->path())) : '';

        return preg_match('/^[0-9a-f]{64}$/', $value) === 1 ? $value : null;
    }

    public function matches(string $candidate): bool
    {
        $value = $this->value();

        return $value !== null && hash_equals($value, $candidate);
    }

    public function hash(): ?string
    {
        $value = $this->value();

        return $value === null ? null : hash('sha256', $value);
    }
}
