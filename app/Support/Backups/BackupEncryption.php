<?php

declare(strict_types=1);

namespace App\Support\Backups;

use Spatie\Backup\Enums\Encryption;

/**
 * CD-10: whether backup:run would encrypt the archive. The package silently writes a plaintext zip for a blank
 * password, for encryption 'none', or for 'default' / 'aes256' on a PHP zip without AES-256, so this mirrors its
 * BackupConfig parsing and Zip task instead of trusting the config. An unknown encryption value counts as none.
 */
final readonly class BackupEncryption
{
    public static function available(): bool
    {
        $password = config('backup.backup.password');

        if (! is_string($password) || $password === '') {
            return false;
        }

        $setting = config('backup.backup.encryption', 'default');

        $encryption = match (true) {
            $setting instanceof Encryption => $setting,
            is_string($setting) => Encryption::tryFrom($setting),
            default => null,
        };

        return $encryption?->shouldEncrypt() ?? false;
    }
}
