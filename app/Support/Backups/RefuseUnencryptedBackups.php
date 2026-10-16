<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Console\Commands\BackupRunCommand;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * CD-10, CD-7: the backstop behind BackupRunCommand. Before backup:run it refuses to start while the archive would not
 * be encrypted (BackupEncryption), because the package writes a plaintext dump for a blank password, encryption
 * 'none' or a zip without AES-256 and still reports success. Laravel fires CommandStarting before the command's
 * handle(), so while backup:run is the app's command (which prints the refusal as one line, O1) this listener steps
 * aside; should the package's command hold the name again, it throws and Artisan exits 1. The schedule does not even
 * start backup:run then (OpsSchedule).
 */
final readonly class RefuseUnencryptedBackups
{
    public const string MESSAGE = 'backup:run refuses to write an unencrypted backup: the running configuration has no BACKUP_ARCHIVE_PASSWORD, '
        .'backup.backup.encryption turns encryption off, or this PHP zip has no AES-256. Run php artisan skv:doctor --fix, '
        .'then php artisan config:cache (or config:clear) when the configuration is cached.';

    public function __invoke(CommandStarting $event): void
    {
        if ($event->command !== 'backup:run' || BackupEncryption::available() || $this->appCommandRefuses()) {
            return;
        }

        throw new RuntimeException(self::MESSAGE);
    }

    /** Whether backup:run is the app's command, which refuses by itself (O1). */
    private function appCommandRefuses(): bool
    {
        return Artisan::findCommand('backup:run') instanceof BackupRunCommand;
    }
}
