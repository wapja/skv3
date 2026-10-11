<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Authorization\Permission;
use App\Authorization\PermissionGroup;
use App\Authorization\Role;

it('has the fifteen permissions of the catalogue', function (): void {
    expect(Permission::values())->toBe([
        'users.view', 'users.create', 'users.update', 'users.delete', 'users.invite.resend', 'users.impersonate',
        'roles.view', 'roles.assign', 'roles.manage',
        'organisations.view', 'organisations.create', 'organisations.update', 'organisations.delete',
        'activity_log.view', 'system.health',
    ]);
});

it('marks exactly the super-only permissions', function (): void {
    $superOnly = array_values(array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->superOnly()));

    expect($superOnly)->toBe([
        Permission::RolesManage, Permission::OrganisationsCreate, Permission::OrganisationsDelete, Permission::SystemHealth,
    ]);
});

it('gives organisation admins the catalogue minus the super-only permissions', function (): void {
    expect(Permission::organisationAdmin())->toHaveCount(11)
        ->and(array_filter(Permission::organisationAdmin(), fn (Permission $permission): bool => $permission->superOnly()))->toBe([]);
});

it('labels, describes and groups every permission in Dutch', function (Permission $permission): void {
    // In English the strings are the translation keys themselves.
    app()->setLocale('en');

    expect(dutchTranslationKeys())->toContain($permission->label(), $permission->description(), $permission->group()->label());
})->with(Permission::cases());

it('labels every role in Dutch', function (Role $role): void {
    app()->setLocale('en');

    expect(dutchTranslationKeys())->toContain($role->label());
})->with(Role::cases());

it('translates permission labels', function (): void {
    app()->setLocale('nl');

    expect(Permission::UsersView->label())->toBe('Gebruikers bekijken')
        ->and(PermissionGroup::ActivityLog->label())->toBe('Activiteitenlog')
        ->and(Role::OrganisationAdmin->label())->toBe('Organisatiebeheerder');
});

it('lists the system role names', function (): void {
    expect(Role::values())->toBe(['super_admin', 'organisation_admin']);
});
