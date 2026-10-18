<?php

declare(strict_types=1);

/** covers §4.9.4, §6.6 */

use App\Support\Uploads\StaleTemporaryUploads;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

beforeEach(function (): void {
    $this->freezeTime();
    // Under test Livewire's temporary disk is the faked tmp-for-tests disk, shared by the whole run: start empty.
    FileUploadConfiguration::storage()->deleteDirectory(FileUploadConfiguration::directory());
});

/** Puts a file on Livewire's temporary disk, last modified $hours (and $minutes) ago. */
$upload = function (string $path, int $hours, int $minutes = 0): void {
    $disk = FileUploadConfiguration::storage();
    $disk->put($path, 'a list of addresses');
    touch($disk->path($path), now()->subHours($hours)->subMinutes($minutes)->getTimestamp());
};

it('deletes the temporary uploads older than 24 hours and keeps the newer ones (E5, CD-4)', function () use ($upload): void {
    $disk = FileUploadConfiguration::storage();
    $upload(FileUploadConfiguration::path('stale.csv'), 24, 1);
    $upload(FileUploadConfiguration::path('stale.csv.json'), 24, 1);
    $upload(FileUploadConfiguration::path('fresh.png'), 1);
    $upload(FileUploadConfiguration::path('almost.png'), 23, 59);
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

it('refuses an upload directory that normalises to the root of the temporary disk (E5)', function (string $directory) use ($upload): void {
    Exceptions::fake();
    config(['livewire.temporary_file_upload.directory' => $directory]);
    $upload('state.json', 48);

    expect(app(StaleTemporaryUploads::class)->removeStale())->toBe(0)
        ->and(FileUploadConfiguration::storage()->exists('state.json'))->toBeTrue();

    Exceptions::assertReported(RuntimeException::class);
    FileUploadConfiguration::storage()->delete('state.json');
})->with(['.', '/', './', 'tmp/..']);

it('refuses a symlinked upload directory on a local disk and never follows it (E5)', function (): void {
    Exceptions::fake();
    $disk = FileUploadConfiguration::storage();
    $target = storage_path('framework/testing/stale-uploads-target-'.getmypid());
    File::ensureDirectoryExists($target);
    file_put_contents($target.'/state.json', 'setup state');
    touch($target.'/state.json', now()->subHours(48)->getTimestamp());
    File::ensureDirectoryExists(dirname($disk->path(FileUploadConfiguration::path())));
    symlink($target, $disk->path(FileUploadConfiguration::path()));

    $removed = app(StaleTemporaryUploads::class)->removeStale();
    $kept = is_file($target.'/state.json');
    unlink($disk->path(FileUploadConfiguration::path()));
    File::deleteDirectory($target);

    expect($removed)->toBe(0)
        ->and($kept)->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});
