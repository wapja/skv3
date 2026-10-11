<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Authorization\LockoutGuard;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\Auth\Sessions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Enables or disables a user (§4.4, DEC-26 (3)). updateStatus is always checked, so nobody disables
 * themselves, super admins included (W2, W12). Pending activation is never set by hand and a pending
 * user's status only changes by accepting the invitation (W4, USR-13). Disabling never removes the
 * last active super admin or the last active organisation admin, whoever asks (DEC-26 (10)), and it
 * ends the user's sessions and cycles the remember token, so enabling them again revives neither
 * (RL-28). Every decision is taken on the stored row, never on a possibly stale model (FU2-5).
 */
final readonly class UpdateUserStatus
{
    public function __construct(private LockoutGuard $lockoutGuard, private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, UserStatus $status): void
    {
        Gate::forUser($actor)->authorize('updateStatus', $target);

        if ($status === UserStatus::PendingActivation) {
            throw ValidationException::withMessages(['status' => [__('Pending activation can\'t be set by hand.')]]);
        }

        DB::transaction(function () use ($actor, $target, $status): void {
            $stored = DB::table('users')->where('id', $target->id)->first(['status', 'is_super_admin']);

            if ($stored === null) {
                return;
            }

            $previous = UserStatus::from((string) $stored->status);

            if ($previous === UserStatus::PendingActivation) {
                throw ValidationException::withMessages(['status' => [__('A pending user becomes active by accepting the invitation.')]]);
            }

            // Align the model with the stored row first, so the save and the logged old status match it.
            $target->forceFill(['status' => $previous])->syncOriginalAttribute('status');

            if ($status === $previous) {
                return;
            }

            if ($previous === UserStatus::Active) {
                $this->lockoutGuard->ensureAnotherActiveAdmin($target, (bool) $stored->is_super_admin, 'status');

                Sessions::endFor([$target->id]);
                $target->forceFill(['remember_token' => null])->syncOriginalAttribute('remember_token');
            }

            $target->forceFill(['status' => $status])->save();

            $this->recordActivity->handle(
                ActivityEvent::UserStatusChanged,
                $target,
                changes: ['old' => ['status' => $previous->value], 'attributes' => ['status' => $status->value]],
                causer: $actor,
            );
        });
    }
}
