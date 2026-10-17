<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Settings\RequestEmailChange;
use App\Authorization\LockoutGuard;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\EmailChangedByAdministrator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The administrator's edit of a user's name and address (§4.4, USR-12). Needs the update ability,
 * so the permission-subset rule applies (RL-26). Addresses are stored in lower case and must be
 * available (W9). Your own address changes only through the signed self-service link on the
 * profile page (E1), and a pending user's address only by cancelling and re-inviting. A changed
 * address applies at once: the old address loses its reset links and any pending self-service
 * change link (FU3-7) and gets a notice after commit (D-14); reset links issued for the new address
 * belong to an earlier holder and go too. user.updated is recorded by UserActivityObserver, not here. It locks
 * the target row through LockoutGuard, decides the policy's preconditions and the pending state again on that row
 * (FU2-5), then writes the row and its reset tokens, which keeps CD-5's order. It takes no organisation lock, so a
 * caller may run it after UpdateUserStatus in one transaction (G4-08); that caller already holds the target's row,
 * and locking it again is harmless.
 */
final readonly class UpdateUser
{
    use ProfileValidationRules;

    public function __construct(private LockoutGuard $lockoutGuard) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $name, string $email): void
    {
        Gate::forUser($actor)->authorize('update', $target);

        $name = trim($name);
        $email = Str::lower(trim($email));
        $previousEmail = $target->email;
        $changesEmail = $email !== $previousEmail;

        if ($changesEmail && $actor->is($target)) {
            throw ValidationException::withMessages(['email' => [__('Change your own address on your profile page.')]]);
        }

        if ($changesEmail && $target->status === UserStatus::PendingActivation) {
            throw ValidationException::withMessages(['email' => [__('Cancel the invitation and invite the new address.')]]);
        }

        Validator::make(['name' => $name, 'email' => $email], [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($target->id, $target->organisation_id),
        ])->validate();

        try {
            DB::transaction(function () use ($actor, $target, $name, $email, $previousEmail, $changesEmail): void {
                // FU2-5: a promotion, a move, a deletion or an acceptance that committed after the policy check.
                $stored = $this->lockoutGuard->lockUsers([$target->id])[$target->id] ?? throw new AuthorizationException;
                $this->lockoutGuard->ensureStillManageable($actor, $target, $stored);

                if ($changesEmail && $stored['status'] === UserStatus::PendingActivation) {
                    throw ValidationException::withMessages(['email' => [__('Cancel the invitation and invite the new address.')]]);
                }

                $target->forceFill(['name' => $name, 'email' => $email])->save();

                if ($changesEmail) {
                    // Reset links issued for the old address must not work any more, and reset tokens are keyed by
                    // address: one issued for the new address belongs to whoever held it before.
                    DB::table('password_reset_tokens')->whereIn('email', [$previousEmail, $email])->delete();
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Another request took the address between validation and save (FU3-8).
            $target->refresh();

            throw ValidationException::withMessages(['email' => [__('This address can\'t be used.')]]);
        }

        if (! $changesEmail) {
            return;
        }

        // A self-service link requested before this change must not move the address again (FU3-7). After the
        // commit: when a caller's surrounding transaction rolls back (the edit page's saveAccount(), G4-08), the
        // address stays and so does the link.
        DB::afterCommit(fn (): bool => Cache::forget(RequestEmailChange::cacheKey($target)));
        // In the target's language, not the administrator's (G3-03).
        Notification::route('mail', $previousEmail)->notify(new EmailChangedByAdministrator($email)->locale($target->locale->value));
    }
}
