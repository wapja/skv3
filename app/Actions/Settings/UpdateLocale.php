<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\Locale;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;

/**
 * Stores the user's language (§4.11). A display preference: not audited (RL-7). While this session impersonates
 * the user, the choice stays in the session for the impersonation and their account keeps its own (G2-05).
 */
final class UpdateLocale
{
    public function handle(User $user, Locale $locale): void
    {
        if (Impersonation::isTarget($user)) {
            Impersonation::rememberPreference($locale);

            return;
        }

        $user->forceFill(['locale' => $locale])->saveQuietly();
    }
}
