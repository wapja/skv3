<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backups\BackupEncryption;
use App\Support\Backups\PrivateBackupTemp;
use App\Support\Backups\RefuseUnencryptedBackups;
use Spatie\Backup\Commands\BackupCommand;

/**
 * CD-7, O1: spatie's backup:run under the same signature. While the archive would not be encrypted, or the temporary
 * directory is not private to this account (M22), it prints the refusal as one translated error line and exits 1. Without it, the backstop listener's exception and stack trace would print
 * (RefuseUnencryptedBackups). The kernel registers discovered commands after the package's, so this class owns the
 * name; it therefore carries no #[AsCommand], which would register it lazily, behind the package's eager command.
 */
final class BackupRunCommand extends BackupCommand
{
    public function handle(): int
    {
        // --config swaps config('backup') only inside parent::handle(); judge the configuration this run will use (CD-7).
        if ($this->option('config')) {
            $this->config = $this->resolveConfig();
        }

        if (! BackupEncryption::available()) {
            $this->components->error(__(RefuseUnencryptedBackups::MESSAGE));

            return self::FAILURE;
        }

        // CD-10, M22: the plaintext dump goes to the temporary directory, which must be private to this account.
        if (! app(PrivateBackupTemp::class)->secure()) {
            $this->components->error(__(PrivateBackupTemp::MESSAGE));

            return self::FAILURE;
        }

        return parent::handle();
    }
}
