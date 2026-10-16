<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Backups\BackupEncryption;

it('counts default and aes256 archives as unencrypted on a PHP zip without AES-256 (CD-10, L-23)', function (string $encryption, bool $withoutAes256): void {
    config(['backup.backup.password' => 'correct-horse-battery', 'backup.backup.encryption' => $encryption]);

    expect(BackupEncryption::available(zipHasAes256: false))->toBe($withoutAes256)
        ->and(BackupEncryption::available(zipHasAes256: true))->toBeTrue()
        ->and(BackupEncryption::available())->toBeTrue();
})->with([
    'default' => ['default', false],
    'aes256' => ['aes256', false],
    'aes128 needs no AES-256' => ['aes128', true],
]);

it('never counts a missing password or no encryption as encrypted, whatever the zip offers (CD-10)', function (array $config): void {
    config(['backup.backup.password' => 'correct-horse-battery', 'backup.backup.encryption' => 'default', ...$config]);

    expect(BackupEncryption::available(zipHasAes256: true))->toBeFalse();
})->with([
    'blank password' => [['backup.backup.password' => '']],
    'encryption none' => [['backup.backup.encryption' => 'none']],
    'unknown encryption' => [['backup.backup.encryption' => 'aes-256']],
]);
