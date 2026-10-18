<?php

declare(strict_types=1);

namespace App\Support\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use RuntimeException;

/**
 * E5, CD-4: Livewire deletes a temporary upload older than 24 hours only when someone uploads again
 * (WithFileUploads::cleanupOldUploads()), so a photo or an address list left behind on an abandoned page, a photo
 * with its EXIF and GPS data, stays on disk until then. The schedule's hourly livewire-tmp-cleanup removes them with
 * Livewire's own threshold, so no open page loses its upload earlier than it would today.
 *
 * It mirrors cleanupOldUploads() of livewire/livewire 4.4 (FileUploadConfiguration is not public API: check this
 * class when Livewire changes it): the configured temporary disk and directory, nothing on an S3 disk, whose
 * clean-up is a bucket lifecycle rule (php artisan livewire:configure-s3-upload-cleanup), and nothing while
 * Livewire's own clean-up is switched off (livewire.temporary_file_upload.cleanup), as for an app that processes the
 * temporary files later.
 *
 * Unlike Livewire's sweep it refuses a directory that normalises to the disk's root ('.', '/', 'tmp/..'), which would
 * age out every file there (on the default disk all of storage/app/private, the setup state included), and on a local
 * disk a symlinked directory, whose target it would otherwise empty: both are reported, because only a config or
 * filesystem change by the owner fixes them, and nothing is removed.
 */
final readonly class StaleTemporaryUploads
{
    /** Livewire's own threshold for a stale temporary upload. */
    public const int STALE_AFTER_HOURS = 24;

    /** Removes the temporary uploads older than STALE_AFTER_HOURS; returns how many. */
    public function removeStale(): int
    {
        if (FileUploadConfiguration::isUsingS3() || ! FileUploadConfiguration::shouldCleanupOldUploads()) {
            return 0;
        }

        if (FileUploadConfiguration::directory() === '') {
            report(new RuntimeException('livewire-tmp-cleanup skipped: livewire.temporary_file_upload.directory points at the root of the temporary disk.'));

            return 0;
        }

        $disk = FileUploadConfiguration::storage();

        if ($disk instanceof FilesystemAdapter
            && $disk->getAdapter() instanceof LocalFilesystemAdapter
            && is_link($disk->path(FileUploadConfiguration::path()))) {
            report(new RuntimeException('livewire-tmp-cleanup skipped: the temporary upload directory is a symbolic link.'));

            return 0;
        }

        $cutoff = Date::now()->subHours(self::STALE_AFTER_HOURS)->getTimestamp();
        $removed = 0;

        foreach ($disk->allFiles(FileUploadConfiguration::path()) as $path) {
            try {
                $stale = $disk->lastModified($path) < $cutoff;
            } catch (FilesystemException) {
                // Gone meanwhile: Livewire's own clean-up, or a page that deleted its upload, got there first.
                continue;
            }

            if ($stale && $disk->delete($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
