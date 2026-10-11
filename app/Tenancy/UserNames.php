<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;
use App\Tenancy\Facades\Tenant;

/**
 * Display names of users across organisations, super admins and deleted users included, for inviters,
 * causers and impersonators (§4.5, §4.8, RL-9). Callers pass only ids taken from rows they already read
 * through their own scope, never an id the client sent (RK-M4). Unknown ids are left out; callers show
 * "Deleted user" for them (W4).
 */
final class UserNames
{
    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function of(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return Tenant::bypass(fn (): array => User::withTrashed()
            ->whereKey($ids)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all());
    }
}
