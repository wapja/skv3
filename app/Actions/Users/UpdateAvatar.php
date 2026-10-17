<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Image\ImageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exceptions\DecoderException;
use RuntimeException;
use Throwable;

/**
 * Stores a user's own profile photo (§6.6). The upload is never stored as sent: an image whose header declares more
 * than 4096 pixels on a side is refused before decoding; the rest goes through Laravel's image API on the GD driver
 * (intervention/image ^4), which turns it by its EXIF orientation (ext-exif; without it the photo keeps its stored
 * orientation), crops it to a 512 × 512 square from the centre and encodes it as WebP, which carries no metadata.
 * The file goes to the public disk under avatars/{organisation id or "platform"}/{user id}-{random}.webp before the
 * transaction and is removed again when the transaction fails. Inside it the user's row is locked alone (CD-5 step
 * 3) to read the previous path, the new path is saved quietly and user.updated is recorded with changes.avatar =
 * "set", never the path (§4.8); the previous file is deleted after commit. The page validates the upload first
 * (type, size, dimensions); an image that cannot be decoded is a validation error on "avatar". Never during an
 * impersonation.
 */
final readonly class UpdateAvatar
{
    public const int SIZE = 512;

    public const int QUALITY = 85;

    /** The longest side decoded: the page's dimensions rule, checked again here before GD allocates the pixels. */
    public const int MAX_SIDE = 4096;

    /**
     * Bytes GD and intervention hold per pixel while a photo is decoded, turned and cropped (measured with
     * intervention/image 4.3: a 4032 × 3024 JPEG with EXIF orientation 6 peaks at 198 MB, ~16 per pixel).
     */
    public const int BYTES_PER_PIXEL = 16;

    private const int MEMORY_HEADROOM = 64 * 1024 * 1024;

    public function __construct(
        private RecordActivity $recordActivity,
        private ImageManager $images,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, UploadedFile $file): void
    {
        if (Impersonation::isActive()) {
            throw new AuthorizationException('The profile photo is not changed during an impersonation.');
        }

        $encoded = $this->encode($file);
        $disk = Storage::disk('public');
        $path = sprintf('avatars/%s/%d-%s.webp', $user->organisation_id ?? 'platform', $user->id, Str::random(20));

        if (! $disk->put($path, $encoded)) {
            throw new RuntimeException('The profile photo could not be stored.');
        }

        try {
            DB::transaction(function () use ($user, $path, $disk): void {
                $previous = DB::table('users')->where('id', $user->id)->lockForUpdate()->value('avatar_path');

                $user->forceFill(['avatar_path' => $path])->saveQuietly();
                $this->recordActivity->handle(ActivityEvent::UserUpdated, $user, changes: ['attributes' => ['avatar' => 'set']]);

                if (is_string($previous) && $previous !== '') {
                    DB::afterCommit(fn (): bool => $disk->delete($previous));
                }
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }

    /** @throws ValidationException */
    private function encode(UploadedFile $file): string
    {
        // get(), not getContent(): a Livewire upload on a remote temporary disk has no local path.
        $contents = $file->get();

        if (! is_string($contents) || $contents === '') {
            throw $this->unreadable();
        }

        // The header alone, before decoding: a small file can declare a huge canvas (a decompression bomb).
        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw $this->unreadable();
        }

        $this->ensureMemoryFor($size[0] * $size[1]);

        try {
            return $this->images->fromBytes($contents)
                ->usingGd()
                ->orient()
                ->cover(self::SIZE, self::SIZE)
                ->toWebp()
                ->quality(self::QUALITY)
                ->toBytes();
        } catch (ImageException $exception) {
            // A format the driver refuses (no previous) or a failed decode is the upload's fault; anything else, such
            // as a GD build without WebP, is the server's and stays a reported error.
            $cause = $exception->getPrevious();

            if (! $cause instanceof Throwable || $cause instanceof DecoderException) {
                throw $this->unreadable();
            }

            throw $exception;
        }
    }

    /**
     * Running out of memory is a fatal error no catch sees, and an ordinary 12 MP phone photo needs about 200 MB: raise
     * memory_limit for this request when the photo needs more, or refuse the photo when the host forbids raising it
     * (php_admin_value). Plan review K5/K8.
     *
     * @throws ValidationException
     */
    private function ensureMemoryFor(int $pixels): void
    {
        $needed = memory_get_usage() + $pixels * self::BYTES_PER_PIXEL + self::MEMORY_HEADROOM;
        $limit = ini_parse_quantity(ini_get('memory_limit'));

        if ($limit === -1 || $limit >= $needed) {
            return;
        }

        if (ini_set('memory_limit', (string) $needed) === false) {
            throw $this->unreadable();
        }
    }

    private function unreadable(): ValidationException
    {
        return ValidationException::withMessages(['avatar' => [__('This image could not be processed.')]]);
    }
}
