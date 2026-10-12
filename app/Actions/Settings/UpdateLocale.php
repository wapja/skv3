<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\Locale;
use App\Models\User;

/** Stores the user's language (§4.11). A display preference: not audited (RL-7). */
final class UpdateLocale
{
    public function handle(User $user, Locale $locale): void
    {
        $user->forceFill(['locale' => $locale])->saveQuietly();
    }
}
