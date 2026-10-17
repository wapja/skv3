<?php

declare(strict_types=1);

/** covers §4.6.2, §4.6.5, §4.8.7, §6.8 */

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

it('creates a custom role in the organisation\'s team with catalogue permissions (ROLE-8)', function (): void {
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();

    $role = app(CreateRole::class)->handle($actor, $organisation, '  Helpdesk  ', [
        Permission::UsersView, Permission::UsersUpdate, Permission::UsersView,
    ]);

    expect($role->name)->toBe('Helpdesk')
        ->and((int) $role->getAttribute('team_id'))->toBe($organisation->id)
        ->and($role->guard_name)->toBe('web')
        ->and(RoleModel::query()->whereKey($role->getKey())->firstOrFail()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['users.update', 'users.view']);
});

it('never creates a role without a team', function (): void {
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();
    onAdminHost();

    app(CreateRole::class)->handle($actor, $organisation, 'Support', [Permission::UsersView]);

    expect(PermissionsTeam::current())->toBeNull()
        ->and(RoleModel::query()->whereNull('team_id')->pluck('name')->sort()->values()->all())
        ->toBe(['organisation_admin', 'super_admin'])
        ->and(RoleModel::query()->where('name', 'Support')->pluck('team_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$organisation->id]);
});

it('refuses super-only permissions and, with the module off, users.impersonate (ROLE-9, ROLE-17)', function (Permission $permission, bool $moduleOn): void {
    useImpersonationModule($moduleOn);
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();

    expect(fn () => app(CreateRole::class)->handle($actor, $organisation, 'Support', [Permission::UsersView, $permission]))
        ->toThrow(ValidationException::class, __('One or more permissions cannot be given to a custom role.'));
    expect(RoleModel::query()->where('name', 'Support')->exists())->toBeFalse();
})->with([
    'roles.manage' => [Permission::RolesManage, true],
    'organisations.create' => [Permission::OrganisationsCreate, true],
    'organisations.delete' => [Permission::OrganisationsDelete, true],
    'system.health' => [Permission::SystemHealth, true],
    'users.impersonate with the module off' => [Permission::UsersImpersonate, false],
]);

it('keeps users.impersonate on a role saved while the module is off (CV-4, §6.8)', function (): void {
    useImpersonationModule(true);
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();
    $role = app(CreateRole::class)->handle($actor, $organisation, 'Support', [Permission::UsersView, Permission::UsersImpersonate]);

    useImpersonationModule(false);
    app(UpdateRole::class)->handle($actor, $organisation, (int) $role->getKey(), 'Support', [Permission::UsersView, Permission::UsersUpdate]);

    expect(RoleModel::query()->whereKey($role->getKey())->firstOrFail()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['users.impersonate', 'users.update', 'users.view']);
});

it('refuses system role names in any case or spacing (W6)', function (string $name): void {
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();
    $custom = app(CreateRole::class)->handle($actor, $organisation, 'Support', []);

    expect(fn () => app(CreateRole::class)->handle($actor, $organisation, $name, []))
        ->toThrow(ValidationException::class, __('This name is reserved for a system role.'))
        ->and(fn () => app(UpdateRole::class)->handle($actor, $organisation, (int) $custom->getKey(), $name, []))
        ->toThrow(ValidationException::class, __('This name is reserved for a system role.'));
    expect(RoleModel::query()->where('team_id', $organisation->id)->pluck('name')->all())->toBe(['Support']);
})->with(['organisation_admin', ' Organisation_Admin ', 'organisation admin', 'SUPER-ADMIN', 'super admin']);

it('refuses renaming, re-permissioning or deleting the template and the badge, for super admins too (W6)', function (Role $system): void {
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();
    $role = RoleModel::query()->whereNull('team_id')->where('name', $system->value)->firstOrFail();
    $before = $role->permissions->pluck('name')->sort()->values()->all();

    expect(fn () => app(UpdateRole::class)->handle($actor, $organisation, (int) $role->getKey(), 'Renamed', []))
        ->toThrow(ValidationException::class, __('System roles cannot be changed.'))
        ->and(fn () => app(UpdateRole::class)->handle($actor, $organisation, (int) $role->getKey(), $system->value, [Permission::UsersView]))
        ->toThrow(ValidationException::class, __('System roles cannot be changed.'))
        ->and(fn () => app(DeleteRole::class)->handle($actor, $organisation, (int) $role->getKey()))
        ->toThrow(AuthorizationException::class);

    $after = RoleModel::query()->whereKey($role->getKey())->firstOrFail();

    expect($after->name)->toBe($system->value)
        ->and($after->permissions->pluck('name')->sort()->values()->all())->toBe($before);
})->with([Role::OrganisationAdmin, Role::SuperAdmin]);

it('finds roles by id only inside the organisation\'s team (W1)', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $actor = asSuperAdmin();
    $foreign = app(CreateRole::class)->handle($actor, $other, 'Finance', [Permission::UsersView]);

    expect(fn () => app(UpdateRole::class)->handle($actor, $own, (int) $foreign->getKey(), 'Hijacked', []))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteRole::class)->handle($actor, $own, (int) $foreign->getKey()))
        ->toThrow(ModelNotFoundException::class);
    expect(RoleModel::query()->whereKey($foreign->getKey())->firstOrFail()->name)->toBe('Finance');
});

it('refuses roles in an archived organisation or another organisation from a tenant host (RK-I11)', function (): void {
    $archived = Organisation::factory()->create();
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $actor = asSuperAdmin();
    $role = app(CreateRole::class)->handle($actor, $other, 'Finance', []);
    $archived->delete();

    expect(fn () => app(CreateRole::class)->handle($actor, $archived, 'Support', []))
        ->toThrow(ValidationException::class, __('This organisation is archived.'));

    onTenant($own);

    expect(fn () => app(CreateRole::class)->handle($actor, $other, 'Support', []))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateRole::class)->handle($actor, $other, (int) $role->getKey(), 'Renamed', []))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteRole::class)->handle($actor, $other, (int) $role->getKey()))
        ->toThrow(AuthorizationException::class);
    expect(RoleModel::query()->where('name', 'Support')->exists())->toBeFalse()
        ->and(RoleModel::query()->whereKey($role->getKey())->firstOrFail()->name)->toBe('Finance');
});

it('refuses duplicate names within a team and allows them across teams', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $actor = asSuperAdmin();
    app(CreateRole::class)->handle($actor, $own, 'Support', []);
    $billing = app(CreateRole::class)->handle($actor, $own, 'Billing', []);

    expect(fn () => app(CreateRole::class)->handle($actor, $own, 'support', []))
        ->toThrow(ValidationException::class, __('This organisation already has a role with this name.'))
        ->and(fn () => app(UpdateRole::class)->handle($actor, $own, (int) $billing->getKey(), 'SUPPORT', []))
        ->toThrow(ValidationException::class, __('This organisation already has a role with this name.'));

    $copy = app(CreateRole::class)->handle($actor, $other, 'Support', []);
    $renamed = app(UpdateRole::class)->handle($actor, $own, (int) $billing->getKey(), ' Billing ', []);

    expect((int) $copy->getAttribute('team_id'))->toBe($other->id)
        ->and($renamed->name)->toBe('Billing');
});

it('deletes a custom role, takes it from its holders and reports the count (X14)', function (): void {
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();
    $role = app(CreateRole::class)->handle($actor, $organisation, 'Support', [Permission::UsersView]);
    $holders = User::factory()->for($organisation)->count(2)->create();
    withPermissionsTeam($organisation->id, function () use ($holders, $role): void {
        $holders->each(fn (User $holder): User => $holder->assignRole($role));
    });

    expect(app(DeleteRole::class)->handle($actor, $organisation, (int) $role->getKey()))->toBe(2)
        ->and(RoleModel::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->where('role_id', $role->getKey())->count())->toBe(0)
        ->and(withPermissionsTeam(
            $organisation->id,
            fn (): bool => (bool) $holders->first()?->fresh()?->hasPermissionTo(Permission::UsersView->value),
        ))->toBeFalse();
});

it('lets only roles.manage holders manage roles', function (): void {
    $organisation = Organisation::factory()->create();
    $role = app(CreateRole::class)->handle(asSuperAdmin(), $organisation, 'Support', []);
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(CreateRole::class)->handle($admin, $organisation, 'Billing', []))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateRole::class)->handle($admin, $organisation, (int) $role->getKey(), 'Renamed', []))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteRole::class)->handle($admin, $organisation, (int) $role->getKey()))
        ->toThrow(AuthorizationException::class);
    expect(RoleModel::query()->where('team_id', $organisation->id)->pluck('name')->all())->toBe(['Support']);
});

it('refuses a non-super roles.manage holder creating a role in another organisation without a host (RK-M9)', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $manager = User::factory()->for($own)->create();
    withPermissionsTeam($own->id, fn (): User => $manager->givePermissionTo(Permission::RolesManage->value));
    $manager->unsetRelation('permissions');

    expect(fn (): mixed => withPermissionsTeam($own->id, fn (): RoleModel => app(CreateRole::class)->handle($manager, $other, 'Forged', [])))
        ->toThrow(AuthorizationException::class);
    expect(RoleModel::query()->where('name', 'Forged')->exists())->toBeFalse();
});

it('records role.created, role.updated with the permission diff and role.deleted, tagged with the role\'s organisation (ROLE-19, RK-I5)', function (): void {
    $organisation = Organisation::factory()->create();
    $actor = asSuperAdmin();
    onAdminHost();

    $role = app(CreateRole::class)->handle($actor, $organisation, 'Support', [Permission::UsersView]);
    app(UpdateRole::class)->handle($actor, $organisation, (int) $role->getKey(), 'Support', [Permission::UsersView]);
    app(UpdateRole::class)->handle($actor, $organisation, (int) $role->getKey(), 'Helpdesk', [Permission::UsersView, Permission::UsersUpdate]);
    app(DeleteRole::class)->handle($actor, $organisation, (int) $role->getKey());

    $created = recordedActivity(ActivityEvent::RoleCreated)->sole();
    $updated = recordedActivity(ActivityEvent::RoleUpdated)->sole(); // the unchanged save wrote nothing
    $deleted = recordedActivity(ActivityEvent::RoleDeleted)->sole();

    expect([(int) $created->organisation_id, (int) $updated->organisation_id, (int) $deleted->organisation_id])
        ->toBe([$organisation->id, $organisation->id, $organisation->id])
        ->and([(int) $created->causer_id, (int) $updated->causer_id, (int) $deleted->causer_id])
        ->toBe([$actor->id, $actor->id, $actor->id])
        ->and((int) $created->subject_id)->toBe((int) $role->getKey())
        ->and($created->properties?->toArray())->toMatchArray(['name' => 'Support', 'permissions' => ['users.view']])
        ->and($updated->attribute_changes?->toArray())->toEqual([
            'old' => ['name' => 'Support', 'permissions' => ['users.view']],
            'attributes' => ['name' => 'Helpdesk', 'permissions' => ['users.update', 'users.view']],
        ])
        ->and($deleted->properties?->toArray())->toMatchArray(['name' => 'Helpdesk', 'holders' => 0]);
});
