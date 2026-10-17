<?php

declare(strict_types=1);

namespace App\Support\Uploads;

use Illuminate\Support\Facades\Date;
use League\Flysystem\FilesystemException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

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

        $disk = FileUploadConfiguration::storage();
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
