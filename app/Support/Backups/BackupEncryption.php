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
    /**
     * @param  bool|null  $zipHasAes256  whether this PHP's zip writes AES-256; null asks ZipArchive. Tests pass false to
     *                                   stand in for a PHP without it (L-23). It only narrows the answer: the package's
     *                                   own check must pass as well.
     */
    public static function available(?bool $zipHasAes256 = null): bool
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

        if (! $encryption instanceof Encryption || ! $encryption->shouldEncrypt()) {
            return false;
        }

        // 'default' and 'aes256' need AES-256; without it the package writes a plaintext zip.
        return ! in_array($encryption, [Encryption::Default, Encryption::Aes256], true)
            || ($zipHasAes256 ?? defined(ZipArchive::class.'::EM_AES_256'));
    }
}
