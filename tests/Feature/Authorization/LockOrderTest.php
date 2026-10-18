<?php

declare(strict_types=1);

/** covers §4.3, §4.4, §4.5, §4.6, §6.3, §6.6 */

use App\Actions\Auth\IssueHandoff;
use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\CancelInvitation;
use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\RemindInvitation;
use App\Actions\Organisations\ArchiveOrganisation;
use App\Actions\Organisations\CreateOrganisation;
use App\Actions\Organisations\PurgeOrganisation;
use App\Actions\Organisations\RestoreOrganisation;
use App\Actions\Organisations\UpdateOrganisation;
use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RemoveAvatar;
use App\Actions\Users\RestoreUser;
use App\Actions\Users\UpdateAvatar;
use App\Actions\Users\UpdateUser;
use App\Actions\Users\UpdateUserRoles;
use App\Actions\Users\UpdateUserStatus;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

/*
| CD-5, one lock order: inside a transaction, the organisation, then the roles, then one users batch, then
| role assignments, invitations, sessions and reset tokens, then activity. SQLite takes one database-wide
| write lock and compiles no FOR UPDATE, so these tests need PostgreSQL's row locks.
*/

/**
 * The tables of the explicit row locks, in order. Only a repeat of the organisation lock is merged (InviteUser's
 * nested UpdateUserRoles locks the organisation it already holds again); any other table locked twice is a CD-5
 * violation and shows up twice (G4-06 was two users batches in a row).
 *
 * @param  list<array{statement: string, table: string, sql: string, bindings: list<mixed>}>  $statements
 * @return list<string>
 */
$lockedTables = function (array $statements): array {
    $tables = [];

    foreach ($statements as $statement) {
        if ($statement['statement'] === 'for update' && ($statement['table'] !== 'organisations' || end($tables) !== 'organisations')) {
            $tables[] = $statement['table'];
        }
    }

    return $tables;
};

/**
 * The position of the first statement of this kind on this table.
 *
 * @param  list<array{statement: string, table: string, sql: string, bindings: list<mixed>}>  $statements
 */
$firstIndex = function (array $statements, string $statement, string $table): int {
    foreach ($statements as $index => $entry) {
        if ($entry['statement'] === $statement && $entry['table'] === $table) {
            return $index;
        }
    }

    throw new LogicException("No {$statement} statement on {$table} ran.");
};

/** An organisation admin of $organisation, without signing anyone in. */
$adminOf = function (Organisation $organisation): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');

    return $user;
};

it('takes its row locks in the one order of CD-5', function (Closure $arrange, array $expected) use ($lockedTables): void {
    $act = $arrange();
    $statements = lockStatements($act);

    expect($lockedTables($statements))->toBe($expected);

    // A write takes an implicit row lock: none may come before the organisation lock (an invitation updated before
    // lockForWrite() ran would put invitations ahead of organisations). Inserts of new rows take no lock another
    // transaction waits on, so CreateOrganisation's own organisation row is not counted.
    $cd5Tables = ['organisations', 'roles', 'users', 'model_has_roles', 'invitations', 'sessions', 'handoff_tokens', 'password_reset_tokens'];
    $first = array_values(array_filter($statements, fn (array $entry): bool => in_array($entry['table'], $cd5Tables, true) && $entry['statement'] !== 'insert'))[0] ?? null;

    if (($expected[0] ?? null) === 'organisations') {
        expect([$first['statement'] ?? null, $first['table'] ?? null])->toBe(['for update', 'organisations']);
    }

    // Every users lock of more than one row takes the rows in id order, so two batches never wait on each other in
    // opposite orders; a lock of one row by its id needs no order.
    foreach ($statements as $entry) {
        $oneRowById = str_ends_with($entry['sql'], ' limit 1 for update') && preg_match('/"id" = \?/', $entry['sql']) === 1;

        if ($entry['statement'] === 'for update' && $entry['table'] === 'users' && ! $oneRowById) {
            expect($entry['sql'])->toContain('order by "id" asc');
        }
    }
})->with([
    'UpdateUserStatus, disabling a member (cycle 3)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $member = User::factory()->for($organisation)->create();
        $admin = asOrgAdmin($organisation);

        return fn () => app(UpdateUserStatus::class)->handle($admin, $member, UserStatus::Disabled);
    }, ['organisations', 'users']],
    'DeleteUser, a member (cycle 3)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $member = User::factory()->for($organisation)->create();
        $admin = asOrgAdmin($organisation);

        return fn () => app(DeleteUser::class)->handle($admin, $member);
    }, ['organisations', 'users']],
    'RestoreUser, a deleted member' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $member = User::factory()->for($organisation)->create();
        $admin = asOrgAdmin($organisation);
        app(DeleteUser::class)->handle($admin, $member);

        return fn () => app(RestoreUser::class)->handle($admin, $member);
    }, ['organisations']],
    'UpdateUser, changing a member\'s address (FU2-5)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $member = User::factory()->for($organisation)->create();
        $admin = asOrgAdmin($organisation);

        return fn () => app(UpdateUser::class)->handle($admin, $member, $member->name, 'moved@example.com');
    }, ['users']],
    'UpdateAvatar, a member\'s own photo (§6.6)' => [function (): Closure {
        Storage::fake('public');
        $member = asMember(Organisation::factory()->create());

        return fn () => app(UpdateAvatar::class)->handle($member, UploadedFile::fake()->image('photo.png', 600, 600));
    }, ['users']],
    'RemoveAvatar, a member\'s own photo (§6.6)' => [function (): Closure {
        Storage::fake('public');
        $member = asMember(Organisation::factory()->create());
        $member->forceFill(['avatar_path' => 'avatars/1/1-photo.webp'])->saveQuietly();

        return fn () => app(RemoveAvatar::class)->handle($member);
    }, ['users']],
    'UpdateUserRoles, taking the template from an admin (G4-06, cycle 4)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $leaving = User::factory()->for($organisation)->create();
        withPermissionsTeam($organisation->id, fn (): User => $leaving->assignRole(Role::OrganisationAdmin->value));
        $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
        $admin = asOrgAdmin($organisation);

        return fn () => app(UpdateUserRoles::class)->handle($admin, $leaving, [(int) $editor->id]);
    }, ['organisations', 'roles', 'users']],
    'SetSuperAdmin, promoting a member' => [function (): Closure {
        $member = User::factory()->for(Organisation::factory())->create();

        return fn () => app(SetSuperAdmin::class)->handle($member, true);
    }, ['organisations', 'users']],
    'SetSuperAdmin, demoting a promoted super admin (the demote/archive race)' => [function (): Closure {
        asSuperAdmin();
        $promoted = User::factory()->for(Organisation::factory())->create();
        app(SetSuperAdmin::class)->handle($promoted, true);

        return fn () => app(SetSuperAdmin::class)->handle($promoted, false);
    }, ['organisations', 'users']],
    'InviteUser' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $admin = asOrgAdmin($organisation);

        return fn () => app(InviteUser::class)->handle($admin, $organisation, 'new@example.com', [Role::templateId()], Locale::default());
    }, ['organisations', 'roles', 'users']],
    'AcceptInvitation (G4-07)' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $invitation = inviteMember($organisation);
        onTenant($organisation);

        return fn () => app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password');
    }, ['organisations', 'users', 'invitations']],
    'CancelInvitation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $admin = asOrgAdmin($organisation);
        $invitation = inviteMember($organisation, $admin);

        return fn () => app(CancelInvitation::class)->handle($admin, $invitation);
    }, ['organisations', 'users', 'invitations']],
    'RemindInvitation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $admin = asOrgAdmin($organisation);
        $invitation = inviteMember($organisation, $admin);

        return fn () => app(RemindInvitation::class)->handle($admin, $invitation);
    }, ['organisations', 'invitations']],
    'CreateOrganisation, which invites its first admin' => [function (): Closure {
        $superAdmin = asSuperAdmin();
        onAdminHost();

        return fn () => app(CreateOrganisation::class)->handle($superAdmin, 'Acme', 'acme', null, 'admin@acme.test', Locale::default());
    }, ['organisations', 'roles', 'users']],
    'ArchiveOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        User::factory()->for($organisation)->count(2)->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();

        return fn () => app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    }, ['organisations', 'users']],
    'RestoreOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();
        app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
        $archived = Organisation::withTrashed()->findOrFail($organisation->id);

        return fn () => app(RestoreOrganisation::class)->handle($superAdmin, $archived);
    }, ['organisations']],
    'PurgeOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        User::factory()->for($organisation)->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();
        app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
        $archived = Organisation::withTrashed()->findOrFail($organisation->id);

        return fn () => app(PurgeOrganisation::class)->handle($superAdmin, $archived, $archived->slug);
    }, ['organisations', 'users']],
    'UpdateOrganisation' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $superAdmin = asSuperAdmin();
        onAdminHost();

        return fn () => app(UpdateOrganisation::class)->handle($superAdmin, $organisation, 'Renamed', $organisation->slug, null, false);
    }, ['organisations']],
    'CreateRole' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $superAdmin = asSuperAdmin();

        return fn () => app(CreateRole::class)->handle($superAdmin, $organisation, 'Helpdesk', [Permission::UsersView]);
    }, ['organisations']],
    'UpdateRole' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $role = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
        $superAdmin = asSuperAdmin();

        return fn () => app(UpdateRole::class)->handle($superAdmin, $organisation, (int) $role->id, 'Editors', [Permission::UsersView]);
    }, ['organisations', 'roles']],
    'DeleteRole' => [function (): Closure {
        $organisation = Organisation::factory()->create();
        $role = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
        $superAdmin = asSuperAdmin();

        return fn () => app(DeleteRole::class)->handle($superAdmin, $organisation, (int) $role->id);
    }, ['organisations', 'roles']],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');

it('locks one users batch, on users only, before the sessions go when a super admin who is also an organisation admin is disabled (G4-06)', function () use ($adminOf, $firstIndex): void {
    $organisation = Organisation::factory()->create();
    $otherAdmin = $adminOf($organisation);
    $leaving = $adminOf($organisation);
    app(SetSuperAdmin::class)->handle($leaving, true);
    $actor = asSuperAdmin();
    onAdminHost();

    $statements = lockStatements(fn () => app(UpdateUserStatus::class)->handle($actor, $leaving, UserStatus::Disabled));
    $locks = array_values(array_filter($statements, fn (array $entry): bool => $entry['statement'] === 'for update'));
    $usersLocks = array_values(array_filter($locks, fn (array $entry): bool => $entry['table'] === 'users'));

    // The organisation first, then exactly one users statement: the target, the team's other admin and (by
    // the is_super_admin predicate) every super admin, in id order, without a join on model_has_roles.
    expect($locks[0]['table'] ?? null)->toBe('organisations')
        ->and($usersLocks)->toHaveCount(1)
        ->and($usersLocks[0]['sql'])->toContain('"is_super_admin" = ?')->toContain('order by "id" asc')->not->toContain(' join ')
        ->and($usersLocks[0]['bindings'])->toContain($otherAdmin->id)->toContain($leaving->id)
        ->and(array_column($locks, 'table'))->not->toContain('model_has_roles')
        ->and($firstIndex($statements, 'for update', 'users'))->toBeLessThan($firstIndex($statements, 'delete', 'sessions'))
        ->and($leaving->fresh()?->status)->toBe(UserStatus::Disabled);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');

it('locks the assigned roles and the users batch before rewriting role assignments (cycle 4)', function () use ($firstIndex): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $admin = asOrgAdmin($organisation);

    $statements = lockStatements(fn () => app(UpdateUserRoles::class)->handle($admin, $member, [(int) $editor->id]));

    expect($firstIndex($statements, 'for update', 'organisations'))->toBe(0)
        ->and($firstIndex($statements, 'for update', 'roles'))->toBeLessThan($firstIndex($statements, 'for update', 'users'))
        ->and($firstIndex($statements, 'for update', 'users'))->toBeLessThan($firstIndex($statements, 'delete', 'model_has_roles'))
        ->and(array_column(array_filter($statements, fn (array $entry): bool => $entry['statement'] === 'for update'), 'table'))->not->toContain('model_has_roles');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');

it('saves the status before the name, after the organisation and users locks, in one transaction (G4-08)', function () use ($firstIndex): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mila Member']);
    asOrgAdmin($organisation);
    $page = Livewire::test('pages::users.edit', ['user' => $member->id])
        ->set('name', 'Mila de Vries')
        ->set('status', UserStatus::Disabled->value);

    $statements = lockStatements(fn () => $page->call('saveAccount')->assertHasNoErrors());
    $firstLockOrWrite = array_values(array_filter($statements, fn (array $entry): bool => $entry['statement'] !== 'insert'))[0] ?? null;

    // UpdateUser's write of the target row used to come first, ahead of UpdateUserStatus's organisation lock.
    expect($firstLockOrWrite['statement'] ?? null)->toBe('for update')
        ->and($firstLockOrWrite['table'] ?? null)->toBe('organisations')
        ->and($firstIndex($statements, 'for update', 'users'))->toBeLessThan($firstIndex($statements, 'update', 'users'))
        ->and($member->fresh()?->only(['name', 'status']))->toEqual(['name' => 'Mila de Vries', 'status' => UserStatus::Disabled]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');

it('rehashes the password, then moves and ends the sessions, then deletes the handoff links, then records ("log out other devices", §6.6)', function (): void {
    // The kit's session driver, so the update moves and deletes real rows. No explicit lock: each write takes its
    // row lock, so the order of the writes is the lock order. The lottery is off, so no garbage collection deletes
    // the inserted row.
    config(['session.driver' => 'database']);
    config(['session.lottery' => [0, 100]]);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = asMember($organisation);
    sessionRowFor($member);
    app(IssueHandoff::class)->handle($member, Hosts::tenant('acme'));
    $page = Livewire::test('pages::settings.sessions')->set('password', 'password');

    $statements = lockStatements(fn () => $page->call('logoutOtherDevices')->assertHasNoErrors());
    $phases = [];

    foreach ($statements as $entry) {
        $phase = match ($entry['statement'].' '.$entry['table']) {
            'update users' => 'users',
            'update sessions', 'delete sessions' => 'sessions',
            'delete handoff_tokens' => 'handoff_tokens',
            'insert activity_log' => 'activity_log',
            default => null,
        };

        // Consecutive writes of one phase count once: this device's row moves, then the other rows go.
        if ($phase !== null && end($phases) !== $phase) {
            $phases[] = $phase;
        }
    }

    expect($phases)->toBe(['users', 'sessions', 'handoff_tokens', 'activity_log']);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL row locks');
