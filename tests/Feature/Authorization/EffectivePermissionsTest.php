<?php

declare(strict_types=1);

/** covers §4.4, §4.6 */

use App\Authorization\EffectivePermissions;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Authorization\UserAbilities;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;

$roleIn = function (Organisation $organisation, string $name, Permission ...$permissions): RoleModel {
    $role = RoleModel::query()->create(['name' => $name, 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));

    return $role;
};

$assign = function (User $user, RoleModel $role, int $teamId): void {
    withPermissionsTeam($teamId, fn (): User => $user->assignRole($role));
    $user->unsetRelation('roles');
};

it('combines role, template and direct permissions of one team', function () use ($roleIn, $assign): void {
    $organisation = Organisation::factory()->create();
    $admin = User::factory()->for($organisation)->create();
    $editor = User::factory()->for($organisation)->create();
    $plain = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $assign($editor, $roleIn($organisation, 'editor', Permission::UsersView, Permission::UsersUpdate), $organisation->id);
    withPermissionsTeam($organisation->id, fn (): User => $editor->givePermissionTo(Permission::RolesAssign->value));
    $templatePermissions = array_map(fn (Permission $permission): string => $permission->value, Permission::organisationAdmin());
    sort($templatePermissions);
    $permissions = app(EffectivePermissions::class);

    expect($permissions->of($admin, $organisation->id))->toBe($templatePermissions)
        ->and($permissions->of($editor, $organisation->id))->toBe([Permission::RolesAssign->value, Permission::UsersUpdate->value, Permission::UsersView->value])
        ->and($permissions->of($plain, $organisation->id))->toBe([])
        ->and($permissions->holdsTemplate([$admin->id, $editor->id, $plain->id], $organisation->id))->toBe([$admin->id]);
});

it('ignores assignments of another team and the super admin badge', function () use ($roleIn, $assign): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $user = User::factory()->for($own)->create();
    $assign($user, $roleIn($other, 'foreign', Permission::UsersDelete), $other->id);
    withPermissionsTeam(null, fn (): User => $user->givePermissionTo(Permission::ActivityLogView->value));
    app(SetSuperAdmin::class)->handle($user, true);
    $permissions = app(EffectivePermissions::class);

    expect($permissions->of($user, $own->id))->toBe([])
        ->and($permissions->of($user, $other->id))->toBe([Permission::UsersDelete->value])
        ->and($permissions->holdsTemplate([$user->id], $own->id))->toBe([]);
});

it('loads a page of users with a fixed number of queries and matches of()', function () use ($roleIn, $assign): void {
    $organisation = Organisation::factory()->create();
    $editor = $roleIn($organisation, 'editor', Permission::UsersView, Permission::UsersUpdate);
    $users = User::factory()->for($organisation)->count(6)->create();

    foreach ($users->values() as $index => $user) {
        if ($index % 2 === 0) {
            $assign($user, $editor, $organisation->id);
        } else {
            withPermissionsTeam($organisation->id, fn (): User => $user->givePermissionTo(Permission::RolesView->value));
        }
    }

    $ids = array_values($users->map(fn (User $user): int => $user->id)->all());
    $permissions = app(EffectivePermissions::class);

    DB::enableQueryLog();
    $sets = $permissions->forUsers($ids, $organisation->id);
    $pageQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $permissions->forUsers([$ids[0]], $organisation->id);
    $singleQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($pageQueries)->toBe(2)
        ->and($singleQueries)->toBe(2)
        ->and(array_keys($sets))->toBe($ids)
        ->and($permissions->forUsers([], $organisation->id))->toBe([]);

    foreach ($users as $user) {
        expect($sets[$user->id])->toBe($permissions->of($user, $organisation->id));
    }
});

it('reads no Spatie relation, so a collection raises no lazy-loading violation (RK-I6)', function () use ($roleIn, $assign): void {
    $organisation = Organisation::factory()->create();
    $editor = $roleIn($organisation, 'editor', Permission::UsersView);

    foreach (User::factory()->for($organisation)->count(3)->create() as $member) {
        $assign($member, $editor, $organisation->id);
    }

    $admin = asOrgAdmin($organisation);
    $users = User::query()->where('organisation_id', $organisation->id)->get();

    expect(fn (): mixed => $users->first()?->roles)->toThrow(LazyLoadingViolationException::class);

    $abilities = app(UserAbilities::class)->forPage($admin, $users);

    foreach ($users as $user) {
        expect(Gate::forUser($admin)->allows('update', $user))->toBe($abilities[$user->id]['update']);
    }

    expect($abilities)->toHaveCount(4);
});
