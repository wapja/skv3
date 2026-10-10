<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of users.is_super_admin (§6.3). Sets the flag and attaches or detaches the
 * super_admin badge (null team) in one transaction. It never removes the last active,
 * non-deleted super admin, nor demotes a super admin who has no organisation. Authorization is
 * the caller's job (policy ability updateSuperAdmin); console commands and seeders call it
 * directly.
 */
final class SetSuperAdmin
{
    /** @throws ValidationException */
    public function handle(User $user, bool $isSuperAdmin): void
    {
        DB::transaction(function () use ($user, $isSuperAdmin): void {
            if (! $isSuperAdmin && $user->isSuperAdmin()) {
                $this->ensureOrganisation($user);
                $this->ensureAnotherActiveSuperAdmin($user);
            }

            $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

            PermissionsTeam::run(null, function () use ($user, $isSuperAdmin): void {
                $user->unsetRelation('roles');

                if ($isSuperAdmin) {
                    $user->assignRole(Role::SuperAdmin->value);
                } else {
                    $user->removeRole(Role::SuperAdmin->value);
                }

                $user->unsetRelation('roles');
            });
        });
    }

    /**
     * A user without an organisation must stay a super admin: a regular user needs one (W8).
     *
     * @throws ValidationException
     */
    private function ensureOrganisation(User $user): void
    {
        if ($user->organisation_id === null) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('Assign an organisation before removing super admin.'),
            ]);
        }
    }

    /** @throws ValidationException */
    private function ensureAnotherActiveSuperAdmin(User $user): void
    {
        // pluck() instead of count(): PostgreSQL refuses FOR UPDATE with aggregates.
        $others = DB::table('users')
            ->where('is_super_admin', true)
            ->where('status', UserStatus::Active->value)
            ->whereNull('deleted_at')
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($others->isEmpty()) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('At least one active super admin must remain.'),
            ]);
        }
    }
}
