<?php

declare(strict_types=1);

/** covers §4.6, §6.2, §6.8 */

use App\Authorization\Permission;
use App\Authorization\PermissionGroup;
use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

/** A custom role of the organisation's team with the given permissions. */
$teamRoleIn = function (Organisation $organisation, string $name, Permission ...$permissions): RoleModel {
    $role = RoleModel::query()->create(['name' => $name, 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->syncPermissions(array_map(fn (Permission $permission): string => $permission->value, $permissions));

    return $role;
};

it('lists the system roles as protected with read-only permissions and the team\'s custom roles (ROLE-20, AUTHZ-3)', function () use ($teamRoleIn): void {
    $organisation = Organisation::factory()->create();
    $support = $teamRoleIn($organisation, 'Support Desk', Permission::UsersView);
    $template = RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->firstOrFail();
    $badge = RoleModel::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->firstOrFail();
    asSuperAdmin();
    onTenant($organisation);

    $this->get(route('roles.index'))
        ->assertOk()
        ->assertSee(Role::OrganisationAdmin->label())
        ->assertSee(Role::SuperAdmin->label())
        ->assertSee(__('Protected'))
        ->assertSee(__('No permissions: the super admin flag grants access.'))
        ->assertSeeHtml('data-test="system-role-permission"')
        ->assertSee('Support Desk')
        ->assertSeeHtml('wire:click="edit('.$support->getKey().')"')
        ->assertSeeHtml('wire:click="confirmDelete('.$support->getKey().')"')
        ->assertDontSeeHtml('wire:click="edit('.$template->getKey().')"')
        ->assertDontSeeHtml('wire:click="confirmDelete('.$template->getKey().')"')
        ->assertDontSeeHtml('wire:click="edit('.$badge->getKey().')"')
        ->assertDontSeeHtml('wire:click="confirmDelete('.$badge->getKey().')"');
});

it('shows organisation admins the roles read-only', function () use ($teamRoleIn): void {
    $organisation = Organisation::factory()->create();
    $teamRoleIn($organisation, 'Support Desk', Permission::UsersView);
    asOrgAdmin($organisation);

    $this->get(route('roles.index'))
        ->assertOk()
        ->assertSee('Support Desk')
        ->assertDontSeeHtml('wire:click="create"')
        ->assertDontSeeHtml('wire:click="edit(')
        ->assertDontSeeHtml('wire:click="confirmDelete(');

    callPageAction(pageSnapshot(route('roles.index'), 'pages::roles.index'), 'create')->assertForbidden();
});

it('shows no other organisation\'s roles on a tenant host and ignores ?organisation= (RK-I4)', function () use ($teamRoleIn): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create(['name' => 'Elsewhere Ltd']);
    $teamRoleIn($own, 'Support Desk');
    $teamRoleIn($other, 'Finance Team');
    asSuperAdmin();
    onTenant($own);

    $this->get(route('roles.index', ['organisation' => $other->id]))
        ->assertOk()
        ->assertSee('Support Desk')
        ->assertDontSee('Finance Team')
        ->assertDontSee('Elsewhere Ltd');

    $this->get(route('roles.index').'?organisation[]=1')->assertOk();
});

it('creates, edits and deletes a custom role through the modal as a super admin', function (): void {
    $organisation = Organisation::factory()->create();
    $holder = User::factory()->for($organisation)->create();
    asSuperAdmin();
    onTenant($organisation);

    Livewire::test('pages::roles.index')
        ->call('create')
        ->assertSet('showRoleModal', true)
        ->set('name', 'Support')
        ->set('permissions', [Permission::UsersView->value])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showRoleModal', false)
        ->assertDispatched('toast-show');

    $role = RoleModel::query()->where('team_id', $organisation->id)->where('name', 'Support')->firstOrFail();
    withPermissionsTeam($organisation->id, fn (): User => $holder->assignRole($role));

    Livewire::test('pages::roles.index')
        ->call('edit', (int) $role->getKey())
        ->assertSet('name', 'Support')
        ->assertSet('permissions', [Permission::UsersView->value])
        ->set('name', 'Helpdesk')
        ->set('permissions', [Permission::UsersView->value, Permission::UsersUpdate->value])
        ->call('save')
        ->assertHasNoErrors()
        ->call('confirmDelete', (int) $role->getKey())
        ->assertSet('showDeleteModal', true)
        ->assertSee(__('Delete the role ":name"? :count users hold it and lose it.', ['name' => 'Helpdesk', 'count' => 1]))
        ->call('delete')
        ->assertHasNoErrors()
        ->assertSet('showDeleteModal', false);

    expect(RoleModel::query()->whereKey($role->getKey())->exists())->toBeFalse();
});

it('requires a super admin on a central host to pick an organisation before creating a role (ROLE-8)', function (): void {
    $organisation = Organisation::factory()->create();
    $archived = Organisation::factory()->create();
    $archived->delete();
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::roles.index')
        ->call('create')
        ->assertHasErrors(['organisation'])
        ->assertSet('showRoleModal', false);

    Livewire::test('pages::roles.index')
        ->set('name', 'Forged')
        ->set('permissions', [Permission::UsersView->value])
        ->call('save')
        ->assertHasErrors(['organisation']);

    Livewire::withQueryParams(['organisation' => (string) $archived->id])
        ->test('pages::roles.index')
        ->call('create')
        ->assertHasErrors(['organisation']);

    Livewire::withQueryParams(['organisation' => (string) $organisation->id])
        ->test('pages::roles.index')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('formOrganisationId', $organisation->id)
        ->set('name', 'Support')
        ->set('permissions', [Permission::UsersView->value])
        ->call('save')
        ->assertHasNoErrors();

    expect(RoleModel::query()->where('name', 'Forged')->exists())->toBeFalse()
        ->and(RoleModel::query()->where('name', 'Support')->pluck('team_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$organisation->id]);
});

it('creates a role only in the host\'s organisation and ignores a forged organisation id on a tenant host (CV-5)', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    asSuperAdmin();
    onTenant($own);
    $snapshot = pageSnapshot(route('roles.index'), 'pages::roles.index');

    callPageAction($snapshot, 'save', updates: [
        'organisation' => (string) $other->id,
        'name' => 'Forged',
        'permissions' => [Permission::UsersView->value],
    ])->assertOk();

    expect(RoleModel::query()->where('name', 'Forged')->pluck('team_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$own->id]);
});

it('refuses a forged edit or delete of another organisation\'s role from a tenant host (W1)', function () use ($teamRoleIn): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $foreign = $teamRoleIn($other, 'Finance Team', Permission::UsersView);
    asSuperAdmin();
    onTenant($own);
    $snapshot = pageSnapshot(route('roles.index'), 'pages::roles.index');

    callPageAction($snapshot, 'edit', [(int) $foreign->getKey()])->assertNotFound();
    callPageAction($snapshot, 'confirmDelete', [(int) $foreign->getKey()])->assertNotFound();

    expect(RoleModel::query()->whereKey($foreign->getKey())->firstOrFail()->name)->toBe('Finance Team');
});

it('refuses a forged edit of the template role (W6)', function (): void {
    $organisation = Organisation::factory()->create();
    $template = RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->firstOrFail();
    $before = $template->permissions->pluck('name')->sort()->values()->all();
    asSuperAdmin();
    onTenant($organisation);
    $snapshot = pageSnapshot(route('roles.index'), 'pages::roles.index');

    callPageAction($snapshot, 'edit', [(int) $template->getKey()])->assertNotFound();
    callPageAction($snapshot, 'confirmDelete', [(int) $template->getKey()])->assertNotFound();

    $after = RoleModel::query()->whereKey($template->getKey())->firstOrFail();

    expect($after->name)->toBe(Role::OrganisationAdmin->value)
        ->and($after->permissions->pluck('name')->sort()->values()->all())->toBe($before);
});

it('answers a validation error for an unknown or super-only permission value (OR-M8)', function (string $value): void {
    $organisation = Organisation::factory()->create();
    asSuperAdmin();
    onTenant($organisation);

    Livewire::test('pages::roles.index')
        ->call('create')
        ->set('name', 'Support')
        ->set('permissions', [Permission::UsersView->value, $value])
        ->call('save')
        ->assertHasErrors(['permissions']);

    expect(RoleModel::query()->where('name', 'Support')->exists())->toBeFalse();
})->with([
    'unknown' => 'nope.permission',
    'super-only' => Permission::RolesManage->value,
]);

it('groups permission checkboxes with their labels and offers no super-only permission', function (): void {
    useImpersonationModule(true);
    $organisation = Organisation::factory()->create();
    asSuperAdmin();
    onTenant($organisation);

    $component = Livewire::test('pages::roles.index')
        ->call('create')
        ->assertSee(PermissionGroup::Users->label())
        ->assertSee(PermissionGroup::Organisations->label())
        ->assertSee(PermissionGroup::ActivityLog->label());

    foreach (Permission::cases() as $permission) {
        if ($permission->superOnly()) {
            $component->assertDontSeeHtml('value="'.$permission->value.'"');
        } else {
            $component->assertSeeHtml('value="'.$permission->value.'"')->assertSee($permission->label());
        }
    }
});

it('hides users.impersonate in the editor when the module is off', function (bool $enabled): void {
    useImpersonationModule($enabled);
    $organisation = Organisation::factory()->create();
    asSuperAdmin();
    onTenant($organisation);

    $component = Livewire::test('pages::roles.index')->call('create');

    if ($enabled) {
        $component->assertSeeHtml('value="'.Permission::UsersImpersonate->value.'"');
    } else {
        $component->assertDontSeeHtml('value="'.Permission::UsersImpersonate->value.'"');
    }
})->with(['on' => true, 'off' => false]);
