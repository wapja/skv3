<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only writer of users.is_super_admin (§6.3). Sets the flag and attaches or detaches the super_admin
 * badge (null team) in one transaction; the badge is synced on every call, also when the flag was already
 * set. It decides on the stored flag, read after its locks, never on a possibly stale model (FU2-5). It
 * never removes the last active, non-deleted super admin (LockoutGuard), nor demotes a super admin who has
 * no organisation (W8) or whose organisation is archived (FU3-4). Records user.super_admin_granted /
 * user.super_admin_revoked when the flag changes (§4.8). Authorization is the caller's job (policy ability
 * updateSuperAdmin); console commands and seeders call it directly, so their entries have no causer.
 * A user without an organisation carries the flag at insert (W8), so handle() sees no change for it:
 * handleCreated() attaches that new user's badge and records the grant.
 */
final readonly class SetSuperAdmin
{
    public function __construct(private LockoutGuard $lockoutGuard, private RecordActivity $recordActivity) {}

    /** @throws ValidationException */
    public function handle(User $user, bool $isSuperAdmin): void
    {
        DB::transaction(function () use ($user, $isSuperAdmin): void {
            if ($isSuperAdmin) {
                // Serialises concurrent promotions of the same user, so the grant is recorded once.
                DB::table('users')->where('id', $user->id)->lockForUpdate()->value('id');
            } elseif ($this->storedFlag($user)) {
                // The lock-out guard locks every super admin row, this one included, in id order (RL-27).
                $this->guardDemotion($user);
            }

            $wasSuperAdmin = $this->storedFlag($user);

            // Align the model with the stored row first, so the save writes only a real change.
            $user->forceFill(['is_super_admin' => $wasSuperAdmin])->syncOriginalAttribute('is_super_admin');
            $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

            $this->syncBadge($user, $isSuperAdmin);

            if ($wasSuperAdmin !== $isSuperAdmin) {
                $this->recordActivity->handle(
                    $isSuperAdmin ? ActivityEvent::SuperAdminGranted : ActivityEvent::SuperAdminRevoked,
                    $user,
                );
            }
        });
    }

    /**
     * Completes a user just inserted with the flag (W8: BelongsToOrganisation refuses a user without an
     * organisation otherwise): attaches the badge and records user.super_admin_granted, once, when the badge
     * was not attached yet (§4.8). Any other user goes through handle().
     *
     * @throws LogicException when the user was not just created with the flag
     */
    public function handleCreated(User $user): void
    {
        if (! $user->wasRecentlyCreated) {
            throw new LogicException('handleCreated() takes a user created in this request; use handle().');
        }

        DB::transaction(function () use ($user): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->value('id');

            if (! $this->storedFlag($user)) {
                throw new LogicException('handleCreated() takes a user inserted with is_super_admin; use handle().');
            }

            $hadBadge = PermissionsTeam::run(null, function () use ($user): bool {
                $user->unsetRelation('roles');

                return $user->hasRole(Role::SuperAdmin->value);
            });

            $this->syncBadge($user, true);

            if (! $hadBadge) {
                $this->recordActivity->handle(ActivityEvent::SuperAdminGranted, $user);
            }
        });
    }

    private function syncBadge(User $user, bool $isSuperAdmin): void
    {
        PermissionsTeam::run(null, function () use ($user, $isSuperAdmin): void {
            $user->unsetRelation('roles');

            if ($isSuperAdmin) {
                $user->assignRole(Role::SuperAdmin->value);
            } else {
                $user->removeRole(Role::SuperAdmin->value);
            }

            $user->unsetRelation('roles');
        });
    }

    private function storedFlag(User $user): bool
    {
        return (bool) DB::table('users')->where('id', $user->id)->value('is_super_admin');
    }

    /** @throws ValidationException */
    private function guardDemotion(User $user): void
    {
        $organisationId = DB::table('users')->where('id', $user->id)->value('organisation_id');

        // A user without an organisation must stay a super admin: a regular user needs one (W8).
        if ($organisationId === null) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('Assign an organisation before removing super admin.'),
            ]);
        }

        // An archived organisation has no host to come home to (FU3-4): restore it first.
        if (Organisation::onlyTrashed()->whereKey($organisationId)->exists()) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('Restore the organisation before removing super admin.'),
            ]);
        }

        $this->lockoutGuard->ensureAnotherActiveSuperAdmin($user, 'is_super_admin');
    }
}
