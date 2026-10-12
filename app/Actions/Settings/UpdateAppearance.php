<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\Appearance;
use App\Models\User;

/** Stores the user's appearance (DEC-12). A display preference: not audited (RL-7). */
final class UpdateAppearance
{
    public function handle(User $user, Appearance $appearance): void
    {
        $user->forceFill(['appearance' => $appearance])->saveQuietly();
    }
}
