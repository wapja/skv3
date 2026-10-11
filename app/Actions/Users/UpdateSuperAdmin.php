<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Authorization\SetSuperAdmin;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The super-admin switch for people (§6.3, RK-M8): only another super admin changes the flag
 * (updateSuperAdmin, always checked), and never on a pending user, whose accept link would then grant it
 * (RK-B3). SetSuperAdmin does the locked write and the audit entry; console commands and seeders keep
 * calling it directly.
 */
final readonly class UpdateSuperAdmin
{
    public function __construct(private SetSuperAdmin $setSuperAdmin) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, bool $isSuperAdmin): void
    {
        Gate::forUser($actor)->authorize('updateSuperAdmin', $target);

        if ($target->status === UserStatus::PendingActivation) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('Cancel the invitation and invite again with the new roles.'),
            ]);
        }

        $this->setSuperAdmin->handle($target, $isSuperAdmin);
    }
}
