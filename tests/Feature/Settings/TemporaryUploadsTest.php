<?php

declare(strict_types=1);

/** covers §6.6 */

use App\Models\Organisation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/** The signed URL a component's _startUpload hands out: relative, as Livewire signs it, bound to no user or session. */
$uploadUrl = fn (): string => URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);

/** The uploads Livewire's temporary directory holds, without their .json metadata files. */
$storedUploads = fn (): array => array_values(array_filter(
    FileUploadConfiguration::storage()->allFiles(FileUploadConfiguration::directory()),
    fn (string $path): bool => ! str_ends_with($path, '.json'),
));

$asJson = ['Accept' => 'application/json'];

// Under test Livewire stores uploads on a fake disk, which exists once its storage() has been asked for.
beforeEach(fn () => FileUploadConfiguration::storage());

it('stores one file of at most 2 MB for a signed-in user (§6.6)', function () use ($uploadUrl, $storedUploads, $asJson): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->post($uploadUrl(), ['files' => [UploadedFile::fake()->create('photo.png', 2048, 'image/png')]], $asJson)
        ->assertOk()
        ->assertJsonCount(1, 'paths');

    expect($storedUploads())->toHaveCount(1);
});

it('refuses a guest, even with a valid signed URL (§6.6)', function () use ($uploadUrl, $storedUploads, $asJson): void {
    onTenant(Organisation::factory()->create(['slug' => 'acme']));

    $this->post($uploadUrl(), ['files' => [UploadedFile::fake()->create('tool.exe', 10)]], $asJson)->assertUnauthorized();

    expect($storedUploads())->toBe([]);
});

it('refuses a file over 2 MB and a request with more or other files than one in "files", storing none (§6.6)', function (Closure $files) use ($uploadUrl, $storedUploads, $asJson): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->post($uploadUrl(), $files(), $asJson)->assertUnprocessable();

    expect($storedUploads())->toBe([]);
})->with([
    'one file over 2 MB' => [fn (): array => ['files' => [UploadedFile::fake()->create('photo.png', 2049, 'image/png')]]],
    'two small files' => [fn (): array => ['files' => [UploadedFile::fake()->create('a.png', 10), UploadedFile::fake()->create('b.png', 10)]]],
    'a second field' => [fn (): array => ['files' => [UploadedFile::fake()->create('a.png', 10)], 'more' => UploadedFile::fake()->create('b.png', 10)]],
    'one file outside a list' => [fn (): array => ['files' => UploadedFile::fake()->create('a.png', 10)]],
]);

it('allows each user ten uploads a minute and sixty an hour (§6.6)', function () use ($uploadUrl, $asJson): void {
    $this->freezeTime();
    asMember(Organisation::factory()->create(['slug' => 'acme']));
    $upload = fn () => $this->post($uploadUrl(), ['files' => [UploadedFile::fake()->create('photo.png', 10, 'image/png')]], $asJson);

    foreach (range(1, 10) as $attempt) {
        $upload()->assertOk();
    }

    $upload()->assertTooManyRequests();

    foreach (range(1, 5) as $minute) {
        $this->travel(61)->seconds();

        foreach (range(1, 10) as $attempt) {
            $upload()->assertOk();
        }
    }

    $this->travel(61)->seconds();
    $upload()->assertTooManyRequests();

    // Another user has limits of their own.
    asMember(Organisation::factory()->create(['slug' => 'beta']));
    $upload()->assertOk();
});
