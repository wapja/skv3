<?php

declare(strict_types=1);

namespace App\Support\Backups;

use Spatie\Backup\Enums\Encryption;
use ZipArchive;

/**
 * CD-10: whether backup:run would encrypt the archive. The package silently writes a plaintext zip for a blank
 * password, for encryption 'none', or for 'default' / 'aes256' on a PHP zip without AES-256, so this mirrors its
 * BackupConfig parsing and Zip task instead of trusting the config. An unknown encryption value counts as none.
 */
final readonly class BackupEncryption
{
    /** BACKUP_ARCHIVE_PASSWORD (backup.backup.password) is blank. */
    public const string NO_PASSWORD = 'password';

    /** backup.backup.encryption is 'none' or a value the package does not know. */
    public const string ENCRYPTION_OFF = 'encryption';

    /** 'default' or 'aes256' on a PHP zip without AES-256. */
    public const string NO_AES256 = 'aes256';

    /**
     * @param  bool|null  $zipHasAes256  whether this PHP's zip writes AES-256; null asks ZipArchive. Tests pass false to
     *                                   stand in for a PHP without it (L-23). It only narrows the answer: the package's
     *                                   own check must pass as well.
     */
    public static function available(?bool $zipHasAes256 = null): bool
    {
        return self::problem($zipHasAes256) === null;
    }

    /**
     * Why backup:run would write a plaintext archive, or null when it encrypts (G6-05): the first of NO_PASSWORD,
     * ENCRYPTION_OFF and NO_AES256 that applies, so LastBackupCheck can name the one cause and its fix.
     *
     * @return self::NO_PASSWORD|self::ENCRYPTION_OFF|self::NO_AES256|null
     */
    public static function problem(?bool $zipHasAes256 = null): ?string
    {
        $password = config('backup.backup.password');

        if (! is_string($password) || $password === '') {
            return self::NO_PASSWORD;
        }

        $setting = config('backup.backup.encryption', 'default');

        $encryption = match (true) {
            $setting instanceof Encryption => $setting,
            is_string($setting) => Encryption::tryFrom($setting),
            default => null,
        };

        if (! $encryption instanceof Encryption || $encryption === Encryption::None) {
            return self::ENCRYPTION_OFF;
        }

        // 'default' and 'aes256' need AES-256; without it the package writes a plaintext zip.
        $needsAes256 = in_array($encryption, [Encryption::Default, Encryption::Aes256], true);

        if (! $encryption->shouldEncrypt() || ($needsAes256 && ! ($zipHasAes256 ?? defined(ZipArchive::class.'::EM_AES_256')))) {
            return self::NO_AES256;
        }

        return null;
    }
}
