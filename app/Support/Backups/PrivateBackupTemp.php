<?php

declare(strict_types=1);

namespace App\Support\Backups;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * CD-10: backup:run's working directory holds the plaintext dump until the archive is encrypted.
 *
 * Before every backup:run, this listener creates the directory with mode 0700, or narrows an existing one to 0700. The
 * package's own subdirectories (0777 & umask) then sit under a private parent (L-07). The schedule's hourly
 * backup-temp-cleanup removes what a killed run left in the package's temp/ subdirectory, also while the backups
 * module is off (F18).
 *
 * A shared directory is never changed or emptied: the system temp directory, a sticky directory, or a directory
 * another account owns.
 */
final readonly class PrivateBackupTemp
{
    /** Files older than this are left over from a killed run: a running backup keeps writing its own. */
    public const int STALE_AFTER_HOURS = 6;

    public function __invoke(CommandStarting $event): void
    {
        if ($event->command === 'backup:run') {
            $this->secure();
        }
    }

    /** Creates the temporary directory with mode 0700, or narrows an existing one to 0700. */
    public function secure(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0700);
        }

        if ($this->isOurs($directory)) {
            chmod($directory, 0700);
        }
    }

    /**
     * Removes the files under the package's working directory (<temporary_directory>/temp) older than
     * STALE_AFTER_HOURS; returns how many. The package writes nowhere else, so the rest of the directory is left alone.
     */
    public function removeStale(): int
    {
        $directory = $this->directory();

        if (! is_dir($directory) || ! $this->isOurs($directory)) {
            return 0;
        }

        $working = $directory.'/temp';

        if (! is_dir($working)) {
            return 0;
        }

        $cutoff = Date::now()->subHours(self::STALE_AFTER_HOURS)->getTimestamp();
        $removed = 0;

        foreach (Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($working) as $file) {
            if ($file->getMTime() < $cutoff && File::delete($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }

    /** The directory the package's TemporaryDirectory uses (BackupServiceProvider binds the same fallback). */
    private function directory(): string
    {
        $configured = config('backup.backup.temporary_directory');

        return is_string($configured) && $configured !== '' ? $configured : storage_path('app/backup-temp');
    }

    /** Whether the directory is this account's own and not a shared one (system temp, sticky, another owner). */
    private function isOurs(string $directory): bool
    {
        clearstatcache(true, $directory);
        $real = realpath($directory);
        $mode = fileperms($directory);
        $user = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        return $real !== false
            && $real !== realpath(sys_get_temp_dir())
            && $mode !== false
            && ($mode & 01000) === 0
            && fileowner($directory) === $user;
    }
}
