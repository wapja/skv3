<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Ends the stored sessions of locked-out users (RL-28): disabled, deleted, or archived or purged with
 * their organisation. With the database session driver (the kit default) deleting the users' rows signs
 * them out on every device; a null remember token makes every "remember me" cookie worthless (the next
 * remember-me sign-in sets a new one). A plain delete by sessions.user_id, inside the caller's transaction.
 */
final class Sessions
{
    /** @param  list<int>  $userIds */
    public static function endFor(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->update(['remember_token' => null]);
    }
}
