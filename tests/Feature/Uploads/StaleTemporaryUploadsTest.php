<?php

declare(strict_types=1);

/** covers §4.9, §6.6 */

use App\Support\Uploads\StaleTemporaryUploads;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

beforeEach(function (): void {
    $this->freezeTime();
    // Under test Livewire's temporary disk is the faked tmp-for-tests disk, shared by the whole run: start empty.
    FileUploadConfiguration::storage()->deleteDirectory(FileUploadConfiguration::directory());
});

/** Puts a file on Livewire's temporary disk, last modified $hours ago. */
$upload = function (string $path, int $hours): void {
    $disk = FileUploadConfiguration::storage();
    $disk->put($path, 'a list of addresses');
    touch($disk->path($path), now()->subHours($hours)->getTimestamp());
};

it('deletes the temporary uploads older than 24 hours and keeps the newer ones (E5, CD-4)', function () use ($upload): void {
    $disk = FileUploadConfiguration::storage();
    $upload(FileUploadConfiguration::path('stale.csv'), 25);
    $upload(FileUploadConfiguration::path('stale.csv.json'), 25);
    $upload(FileUploadConfiguration::path('fresh.png'), 1);
    $upload(FileUploadConfiguration::path('almost.png'), 23);
    // Outside Livewire's directory: never touched, whatever its age.
    $upload('elsewhere/old.txt', 48);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(2)
        ->and($disk->exists(FileUploadConfiguration::path('stale.csv')))->toBeFalse()
        ->and($disk->exists(FileUploadConfiguration::path('stale.csv.json')))->toBeFalse()
        ->and($disk->exists(FileUploadConfiguration::path('fresh.png')))->toBeTrue()
        ->and($disk->exists(FileUploadConfiguration::path('almost.png')))->toBeTrue()
        ->and($disk->exists('elsewhere/old.txt'))->toBeTrue();

    $disk->deleteDirectory('elsewhere');
});

it('follows a configured upload directory (E5)', function () use ($upload): void {
    $disk = FileUploadConfiguration::storage();
    config(['livewire.temporary_file_upload.directory' => 'uploads-tmp']);
    $upload('uploads-tmp/stale.csv', 25);
    $upload('livewire-tmp/stale.csv', 25);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(1)
        ->and($disk->exists('uploads-tmp/stale.csv'))->toBeFalse()
        ->and($disk->exists('livewire-tmp/stale.csv'))->toBeTrue();

    $disk->deleteDirectory('uploads-tmp');
});

it('leaves an S3 temporary disk to its lifecycle rule (E5, CD-4)', function () use ($upload): void {
    $disk = FileUploadConfiguration::storage();
    // Livewire reads the configured disk's driver; under test the files still sit on tmp-for-tests, so nothing is contacted.
    config([
        'livewire.temporary_file_upload.disk' => 'uploads',
        'filesystems.disks.uploads' => ['driver' => 's3', 'bucket' => 'never-contacted'],
    ]);
    $upload(FileUploadConfiguration::path('stale.csv'), 25);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(0)
        ->and($disk->exists(FileUploadConfiguration::path('stale.csv')))->toBeTrue();
});

it('leaves the uploads alone while Livewire\'s clean-up is off (E5)', function () use ($upload): void {
    config(['livewire.temporary_file_upload.cleanup' => false]);
    $upload(FileUploadConfiguration::path('stale.csv'), 25);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(0)
        ->and(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path('stale.csv')))->toBeTrue();
});

it('skips an upload that is gone before its age is read, as a parallel clean-up leaves it (E5)', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->with('livewire-tmp')->andReturn(['livewire-tmp/gone.csv', 'livewire-tmp/stale.csv']);
    $disk->shouldReceive('lastModified')->with('livewire-tmp/gone.csv')->andThrow(UnableToRetrieveMetadata::lastModified('livewire-tmp/gone.csv'));
    $disk->shouldReceive('lastModified')->with('livewire-tmp/stale.csv')->andReturn(now()->subHours(25)->getTimestamp());
    $disk->shouldReceive('delete')->once()->with('livewire-tmp/stale.csv')->andReturnTrue();
    Storage::set('tmp-for-tests', $disk);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(1);
});

it('does nothing while there is no upload directory (E5)', function (): void {
    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(0);
});
