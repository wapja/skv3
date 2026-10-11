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
 * Soft-deletes a user (§4.4, DEC-26 (3)). delete is always checked, so nobody deletes themselves (W2).
 * Deleting an active user never removes the last active super admin or the last active organisation
 * admin, whoever asks (DEC-26 (10), W12). A pending user is removed by cancelling the invitation.
 * The user's sessions end and the remember token is cycled, so a restore revives neither (RL-28); their reset
 * links go too, because reset tokens are keyed by address and the address is free for another account now.
 * Every decision is taken on the stored row, never on a possibly stale model (FU2-5).
 */
final readonly class DeleteUser
{
    public function __construct(private LockoutGuard $lockoutGuard, private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('delete', $target);

        DB::transaction(function () use ($actor, $target): void {
            $stored = DB::table('users')->where('id', $target->id)->first(['email', 'status', 'is_super_admin', 'deleted_at']);

            if ($stored === null || $stored->deleted_at !== null) {
                return;
            }

            if ($stored->status === UserStatus::PendingActivation->value) {
                throw ValidationException::withMessages(['user' => [__('Cancel the invitation instead.')]]);
            }

            if ($stored->status === UserStatus::Active->value) {
                $this->lockoutGuard->ensureAnotherActiveAdmin($target, (bool) $stored->is_super_admin, 'user');
            }

            Sessions::endFor([$target->id]);
            DB::table('password_reset_tokens')->where('email', $stored->email)->delete();
            $target->forceFill(['remember_token' => null])->syncOriginalAttribute('remember_token');
            $target->delete();

            $this->recordActivity->handle(ActivityEvent::UserDeleted, $target, causer: $actor);
        });
    }
}
