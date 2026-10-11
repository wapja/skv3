<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Audit\RecordActivity;
use App\Authorization\Permission;
use App\Authorization\SyncPermissions;
use App\Concerns\ManagesCustomRoles;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a custom role in an organisation's team (§4.6): never without a team (ROLE-8), never
 * with a system role's name (W6), only with permissions the role editor offers (ROLE-9,
 * ROLE-17). The organisation is locked first, so an archived one or, on a tenant host, another
 * organisation is refused (RK-I11).
 */
final readonly class CreateRole
{
    use ManagesCustomRoles;

    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @param  list<Permission>  $permissions
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, string $name, array $permissions): RoleModel
    {
        Gate::forUser($actor)->authorize('create', RoleModel::class);

        if (! $actor->isSuperAdmin() && $actor->organisation_id !== $organisation->id) {
            throw new AuthorizationException; // RK-M9: non-super actors only create roles in their own organisation
        }

        $values = $this->assignablePermissionValues($permissions);

        return DB::transaction(function () use ($actor, $organisation, $name, $values): RoleModel {
            $organisation = HostScope::lockForWrite($organisation);
            $name = $this->validatedRoleName($name, $organisation->id);

            try {
                $role = RoleModel::query()->create([
                    'name' => $name,
                    'guard_name' => SyncPermissions::GUARD,
                    'team_id' => $organisation->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('This organisation already has a role with this name.')]);
            }

            $role->syncPermissions($values);

            $this->recordActivity->handle(
                ActivityEvent::RoleCreated,
                $role,
                properties: ['name' => $name, 'permissions' => $values],
                causer: $actor,
                organisation: $organisation,
            );

            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

            return $role;
        });
    }
}
