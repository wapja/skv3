<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Audit\RecordActivity;
use App\Concerns\ManagesCustomRoles;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deletes a custom role of the organisation's team (§4.6). Assigned roles may be deleted: the
 * holders lose the role, and the number of holders is returned and logged (X14). System roles
 * are refused by the always-checked RolePolicy::delete, for super admins too (W6).
 */
final readonly class DeleteRole
{
    use ManagesCustomRoles;

    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @return int the number of users the role was taken from
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, int $roleId): int
    {
        return DB::transaction(function () use ($actor, $organisation, $roleId): int {
            $organisation = HostScope::lockForWrite($organisation);
            $role = $this->lockedRole($organisation, $roleId);

            Gate::forUser($actor)->authorize('delete', $role);

            $holders = DB::table('model_has_roles')->where('role_id', $role->getKey())->count();
            $name = $role->name;

            // Spatie detaches the role from its holders and its permissions while deleting it (X14).
            $role->delete();

            $this->recordActivity->handle(
                ActivityEvent::RoleDeleted,
                $role,
                properties: ['name' => $name, 'holders' => $holders],
                causer: $actor,
                organisation: $organisation,
            );

            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

            return $holders;
        });
    }
}
