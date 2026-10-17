<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Console\Commands\BackupRunCommand;
use App\Support\ProcessAccount;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use RuntimeException;
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
 * another account owns. Nothing here follows a symbolic link: a temporary directory or a temp/ that is one is left
 * alone, because whoever could once write the directory may have planted it to point at the application (lstat()).
 * backup:run then refuses to start (M22): BackupRunCommand prints MESSAGE as one line, and this listener throws it
 * should the package's command hold the name again, as RefuseUnencryptedBackups does.
 *
 * "This account" is the process's effective uid (ProcessAccount, CD-9). Without ext-posix it is unknown, so no
 * directory is this account's and backup:run refuses. Root owns every system directory, so under euid 0 only a
 * directory inside storage_path() counts as ours (L7b-69): a mistyped /var is never narrowed or emptied.
 */
final readonly class PrivateBackupTemp
{
    /** Files older than this are left over from a killed run: a running backup keeps writing its own. */
    public const int STALE_AFTER_HOURS = 6;

    public const string MESSAGE = 'backup:run refuses to start: its temporary directory (backup.backup.temporary_directory) is a symbolic link, '
        .'a shared or the system temporary directory, or another account\'s, so the database dump would not be private. '
        .'Choose a directory of this account; as root, one inside storage/. Without the PHP posix extension no directory counts as this account\'s.';

    /**
     * @param  ?string  $systemTemp  the system temporary directory; null for sys_get_temp_dir() (tests pass a sandbox)
     */
    public function __construct(private ?string $systemTemp = null) {}

    public function __invoke(CommandStarting $event): void
    {
        if ($event->command !== 'backup:run' || $this->secure() || Artisan::findCommand('backup:run') instanceof BackupRunCommand) {
            return;
        }

        throw new RuntimeException(self::MESSAGE);
    }

    /**
     * Creates the temporary directory with mode 0700, or narrows an existing one to 0700; returns whether it is then
     * private to this account. A shared directory or a link is left alone and is not.
     */
    public function secure(): bool
    {
        $directory = $this->directory();

        if (! is_link($directory) && ! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0700);
        }

        return $this->narrow($directory) && $this->isPrivate($directory);
    }

    /**
     * Removes the files under the package's working directory (<temporary_directory>/temp) older than
     * STALE_AFTER_HOURS; returns how many. The package writes nowhere else, so the rest of the directory is left alone.
     *
     * The temporary directory is narrowed to 0700 first and must then be private, so no other account can swap temp/
     * while it is walked; temp/ must be a real directory of this account. Finder lists a symlinked file, which
     * File::delete() unlinks without touching its target, and never descends into a symlinked directory.
     */
    public function removeStale(): int
    {
        $directory = $this->directory();

        if (! $this->narrow($directory) || ! $this->isPrivate($directory)) {
            return 0;
        }

        $working = $directory.'/temp';

        if (! $this->isOwnDirectory($working)) {
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

    /** Narrows the directory to 0700 when it is this account's own; returns whether it is. */
    private function narrow(string $directory): bool
    {
        if (! $this->isOurs($directory)) {
            return false;
        }

        chmod($directory, 0700);

        return true;
    }

    /** The directory the package's TemporaryDirectory uses (BackupServiceProvider binds the same fallback). */
    private function directory(): string
    {
        $configured = config('backup.backup.temporary_directory');

        return is_string($configured) && $configured !== '' ? $configured : storage_path('app/backup-temp');
    }

    /**
     * Whether the directory is this account's own and not a shared one (system temp, sticky, another owner, a link).
     */
    private function isOurs(string $directory): bool
    {
        $mode = $this->ownDirectoryMode($directory);
        $real = realpath($directory);

        return $mode !== null
            && ($mode & 01000) === 0
            && $real !== false
            && $real !== realpath($this->systemTemp ?? sys_get_temp_dir());
    }

    /** Whether neither the group nor others may write the directory, read past PHP's stat cache. */
    private function isPrivate(string $directory): bool
    {
        $mode = $this->ownDirectoryMode($directory);

        return $mode !== null && ($mode & 0022) === 0;
    }

    private function isOwnDirectory(string $directory): bool
    {
        return $this->ownDirectoryMode($directory) !== null;
    }

    /**
     * The permission bits of a real directory (not a symbolic link) this account owns, read with lstat(); null
     * otherwise.
     */
    private function ownDirectoryMode(string $directory): ?int
    {
        clearstatcache(true, $directory);

        if (! file_exists($directory) && ! is_link($directory)) {
            return null;
        }

        $stat = lstat($directory);
        $user = app(ProcessAccount::class)->uid();

        // CD-9: an unknown account owns nothing.
        if ($user === null || $stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $user) {
            return null;
        }

        // L7b-69: root owns /usr, /var and the rest, so as root only a directory inside storage_path() is ours.
        if ($user === 0 && ! $this->insideStorage($directory)) {
            return null;
        }

        return $stat['mode'] & 07777;
    }

    /** Whether the directory, resolved, lies below storage_path(), resolved (a symlinked storage/ counts as itself). */
    private function insideStorage(string $directory): bool
    {
        $real = realpath($directory);
        $storage = realpath(storage_path());

        return $real !== false && $storage !== false && str_starts_with($real, $storage.'/');
    }
}
