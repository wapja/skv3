<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use Closure;
use RuntimeException;

/**
 * One setup request at a time (§5.3.4): an exclusive flock() on PATH around every load-check-save of the wizard state
 * and the Herd state, so a reload or a second tab never runs a step twice, and nobody writes back a state that
 * another request replaced or used up. File sessions do not serialise requests, and Livewire queues them only within
 * one tab. Re-entrant within one process (the tests' launcher runs skv:run-step inline). The file stays empty.
 */
final class SetupLock
{
    public const string PATH = 'storage/app/private/skv-setup.lock';

    /** @var array<string, true> the lock files this process holds */
    private static array $held = [];

    /**
     * Runs $callback while holding the lock. Without $busy it waits for the lock; with $busy, a lock that another
     * request holds returns $busy() at once.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  (Closure(): T)|null  $busy
     * @return T
     */
    public static function run(string $basePath, Closure $callback, ?Closure $busy = null): mixed
    {
        $path = rtrim($basePath, '/').'/'.self::PATH;

        if (isset(self::$held[$path])) {
            return $callback();
        }

        $handle = self::open($path);

        if (! flock($handle, $busy instanceof Closure ? LOCK_EX | LOCK_NB : LOCK_EX)) {
            fclose($handle);

            return $busy instanceof Closure ? $busy() : throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }

        self::$held[$path] = true;

        try {
            return $callback();
        } finally {
            unset(self::$held[$path]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Removes the lock file once the setup is over; whoever still waits on it finds no state to act on. */
    public static function forget(string $basePath): void
    {
        @unlink(rtrim($basePath, '/').'/'.self::PATH);
    }

    /**
     * The lock file, created empty with mode 0600; a symlink is removed, never followed (RL-5).
     *
     * @return resource
     */
    private static function open(string $path)
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Could not create :directory.', ['directory' => basename($directory)]));
        }

        if (is_link($path)) {
            unlink($path);
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException(__('Could not write :file.', ['file' => basename($path)]));
        }

        chmod($path, 0600);

        return $handle;
    }
}
