<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\Appearance;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;

/**
 * Stores the user's appearance (DEC-12). A display preference: not audited (RL-7). While this session impersonates
 * the user, the choice stays in the session for the impersonation and their account keeps its own (G2-05).
 */
final class UpdateAppearance
{
    public function handle(User $user, Appearance $appearance): void
    {
        if (Impersonation::isTarget($user)) {
            Impersonation::rememberPreference($appearance);

            return;
        }

        $user->forceFill(['appearance' => $appearance])->saveQuietly();
    }
}
