<?php

declare(strict_types=1);

namespace App\Setup\Support;

use JsonException;
use RuntimeException;

/**
 * The setup's small state files (RL-5): written atomically (a temp file in the same directory, then rename(), which
 * replaces a symlink instead of following it) with mode 0600 unless the caller names another (RL-1: ProjectFile and
 * the .env copies), and read back as JSON objects. A read refuses a symlink and a file it cannot read as it refuses a
 * file that is no JSON object, so every caller that catches JsonException treats all three as damaged (RL-2).
 */
final class StateFile
{
    /** @param  array<string, mixed>  $data */
    public static function writeJson(string $path, array $data): void
    {
        self::write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    public static function write(string $path, string $contents, int $mode = 0600): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Could not create :directory.', ['directory' => basename($directory)]));
        }

        // A random name created exclusively ('x' refuses an existing file or symlink); the security preset bans tempnam().
        $temporary = $directory.'/.state-'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x');

        if ($handle === false) {
            throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }

        // The mode before any content goes in, so a secret is never readable at the umask's mode.
        chmod($temporary, $mode);
        $written = fwrite($handle, $contents) === strlen($contents);
        fclose($handle);

        if (! $written || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }
    }

    /**
     * @return array<string, mixed>|null null when the file does not exist
     *
     * @throws JsonException when the file is a symlink, cannot be read or is not a JSON object (RL-2)
     */
    public static function readJson(string $path): ?array
    {
        if (is_link($path)) {
            throw new JsonException(__(':file is a symbolic link.', ['file' => basename($path)]));
        }

        if (! is_file($path)) {
            return null;
        }

        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new JsonException(__(':file is not readable.', ['file' => basename($path)]));
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new JsonException(__(':file is not a JSON object.', ['file' => basename($path)]));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
