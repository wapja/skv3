<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a user's own profile photo (§6.6): the user's row is locked alone (CD-5 step 3) to read the stored path,
 * the path is cleared quietly, user.updated is recorded with changes.avatar = "removed" (never the path, §4.8) and
 * the file is deleted after commit. Without a stored photo nothing is written or recorded, and it returns false, so
 * the page confirms nothing (E55). Never during an impersonation.
 */
final readonly class RemoveAvatar
{
    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * Returns whether a photo was removed.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user): bool
    {
        if (Impersonation::isActive()) {
            throw new AuthorizationException('The profile photo is not changed during an impersonation.');
        }

        return DB::transaction(function () use ($user): bool {
            $previous = DB::table('users')->where('id', $user->id)->lockForUpdate()->value('avatar_path');

            if (! is_string($previous) || $previous === '') {
                return false;
            }

            // The locked row decides, not the model: one loaded before a parallel save still holds null, and clearing
            // it would then write nothing while the file below goes.
            $user->forceFill(['avatar_path' => $previous])->syncOriginalAttribute('avatar_path');
            $user->forceFill(['avatar_path' => null])->saveQuietly();
            $this->recordActivity->handle(ActivityEvent::UserUpdated, $user, changes: ['attributes' => ['avatar' => 'removed']]);

            DB::afterCommit(fn (): bool => Storage::disk('public')->delete($previous));

            return true;
        });
    }
}
