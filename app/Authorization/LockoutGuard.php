<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The lock-out guards (§4.4, §4.6, §6.3): never remove the last active, non-deleted super admin or the
 * last active organisation admin of a team. Each guard locks the whole candidate set, target included,
 * in id order and decides in PHP (RL-27), so two concurrent demotions wait for each other instead of
 * deadlocking. Call inside the caller's transaction. pluck() instead of count() or exists(): PostgreSQL
 * refuses FOR UPDATE with aggregates and in EXISTS subqueries.
 */
final class LockoutGuard
{
    /** @throws ValidationException */
    public function ensureAnotherActiveSuperAdmin(User $user, string $field): void
    {
        $statuses = DB::table('users')
            ->where('is_super_admin', true)
            ->whereNull('deleted_at')
            ->orderBy('users.id')
            ->lockForUpdate()
            ->pluck('users.status', 'users.id');

        if (! $this->anotherActiveRemains($statuses, $user)) {
            throw ValidationException::withMessages([
                $field => __('At least one active super admin must remain.'),
            ]);
        }
    }

    /** @throws ValidationException */
    public function ensureAnotherActiveOrganisationAdmin(User $user, int $teamId, string $field): void
    {
        $statuses = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.role_id', Role::templateId())
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.team_id', $teamId)
            ->whereNull('users.deleted_at')
            ->orderBy('users.id')
            ->lockForUpdate()
            ->pluck('users.status', 'users.id');

        if (! $this->anotherActiveRemains($statuses, $user)) {
            throw ValidationException::withMessages([
                $field => __('An organisation needs at least one active organisation admin.'),
            ]);
        }
    }

    /** @param  Collection<array-key, mixed>  $statuses  status per user id */
    private function anotherActiveRemains(Collection $statuses, User $user): bool
    {
        return $statuses->contains(
            fn (mixed $status, int|string $id): bool => (int) $id !== $user->id && $status === UserStatus::Active->value,
        );
    }
}
