<?php

declare(strict_types=1);

namespace App\Support\Backups;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * CD-10: before backup:run, refuses to start while the archive would not be encrypted (BackupEncryption). The package
 * writes a plaintext dump for a blank password, encryption 'none' or a zip without AES-256 and still reports success,
 * so a backup started by hand would otherwise upload one. The exception aborts the command before anything is dumped;
 * Artisan reports it and exits 1. The schedule does not even start backup:run then (routes/console.php).
 */
final readonly class RefuseUnencryptedBackups
{
    public function __invoke(CommandStarting $event): void
    {
        if ($event->command !== 'backup:run' || BackupEncryption::available()) {
            return;
        }

        throw new RuntimeException(
            'backup:run refuses to write an unencrypted backup: the running configuration has no BACKUP_ARCHIVE_PASSWORD, '
            .'backup.backup.encryption turns encryption off, or this PHP zip has no AES-256. Run php artisan skv:doctor --fix, '
            .'then php artisan config:cache (or config:clear) when the configuration is cached.',
        );
    }
}
