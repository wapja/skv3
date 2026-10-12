<?php

declare(strict_types=1);

/** covers §4.3, §4.8 */

use App\Actions\Invitations\InviteUser;
use App\Actions\Organisations\ArchiveOrganisation;
use App\Actions\Organisations\PurgeOrganisation;
use App\Actions\Organisations\RestoreOrganisation;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Activity;
use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/** A database-driver session row and a "remember me" token, as a signed-in browser leaves them. */
$signIn = function (User $user, string $rememberToken = 'old-remember-token'): void {
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => '',
        'last_activity' => time(),
    ]);
    DB::table('users')->where('id', $user->id)->update(['remember_token' => $rememberToken]);
};

/** Restore and purge decide on a freshly read (trashed) instance, as the organisations index passes it. */
$fresh = fn (Organisation $organisation): Organisation => Organisation::withTrashed()->findOrFail($organisation->id);

$templateRoleId = fn (): int => (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

$deletedAt = fn (User $user): mixed => DB::table('users')->where('id', $user->id)->value('deleted_at');

it('archives in a transaction, cascades and records it (ORG-21)', function () use ($deletedAt): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    // A holder with a typed property, not a by-reference bool or a stdClass: PHPStan would type a captured
    // `true` as always true.
    $audit = new class
    {
        public bool $fails = true;
    };
    Activity::creating(function (Activity $activity) use ($audit): void {
        if ($audit->fails && $activity->event === ActivityEvent::OrganisationArchived->value) {
            throw new RuntimeException('The audit log is unavailable.');
        }
    });

    expect(fn () => app(ArchiveOrganisation::class)->handle($superAdmin, $organisation))->toThrow(RuntimeException::class);
    expect(Organisation::query()->find($organisation->id))->not->toBeNull()
        ->and($deletedAt($member))->toBeNull();

    $audit->fails = false;
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    $archivedAt = DB::table('organisations')->where('id', $organisation->id)->value('deleted_at');
    $entry = recordedActivity(ActivityEvent::OrganisationArchived)->sole();
    expect($archivedAt)->not->toBeNull()
        ->and($deletedAt($member))->toBe($archivedAt)
        ->and($entry->organisation_id)->toBe($organisation->id)
        ->and((int) $entry->causer_id)->toBe($superAdmin->id);
});

it('cuts the cascaded users\' sessions and remember tokens on archive, so a restore revives none (RL-28)', function () use ($signIn, $fresh): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $signIn($member);
    $signIn($promoted, 'promoted-token');

    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    expect(DB::table('sessions')->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $member->id)->value('remember_token'))->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $promoted->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $promoted->id)->value('remember_token'))->toBe('promoted-token');

    app(RestoreOrganisation::class)->handle($superAdmin, $fresh($organisation));

    expect(Auth::createUserProvider('users')?->retrieveByToken($member->id, 'old-remember-token'))->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $member->id)->exists())->toBeFalse();
});

it('restores with its cascaded rows and records it (ORG-8)', function () use ($fresh, $deletedAt): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create();
    $deletedEarlier = User::factory()->for($organisation)->create(['deleted_at' => now()->subHour()]);
    $member = User::factory()->for($organisation)->create();
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    app(RestoreOrganisation::class)->handle($superAdmin, $fresh($organisation));

    expect(Organisation::query()->find($organisation->id))->not->toBeNull()
        ->and($deletedAt($member))->toBeNull()
        ->and($deletedAt($deletedEarlier))->not->toBeNull()
        ->and(recordedActivity(ActivityEvent::OrganisationRestored)->sole()->organisation_id)->toBe($organisation->id);
});

it('refuses to restore while a cascaded user\'s address is taken by another user (C-15)', function () use ($fresh, $deletedAt): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'taken@example.com']);
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    User::factory()->create(['email' => 'taken@example.com']);

    // A try/catch instead of toThrow(Closure): Pest reads the closure's parameter type as the exception
    // class, and an interface such as Throwable is taken for an expected message instead.
    try {
        app(RestoreOrganisation::class)->handle($superAdmin, $fresh($organisation));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'email' => [__('These addresses are now used by other accounts: :addresses.', ['addresses' => 'taken@example.com'])],
        ]);
    }

    expect(Organisation::query()->find($organisation->id))->toBeNull()
        ->and($deletedAt($member))->not->toBeNull();
});

it('refuses a restore or purge whose organisation changed state meanwhile (RK-I10)', function () use ($fresh): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    $stale = $fresh($organisation);
    app(RestoreOrganisation::class)->handle($superAdmin, $fresh($organisation));

    $notArchived = ['organisation' => [__('This organisation is not archived.')]];

    try {
        app(PurgeOrganisation::class)->handle($superAdmin, $stale, 'acme');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe($notArchived);
    }

    try {
        app(RestoreOrganisation::class)->handle($superAdmin, $stale);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe($notArchived);
    }
    expect(Organisation::query()->find($organisation->id))->not->toBeNull();
});

it('purges only an archived organisation and only after the slug is typed (ORG-9, ORG-10)', function () use ($fresh): void {
    $superAdmin = asSuperAdmin();
    $active = Organisation::factory()->create(['slug' => 'still-active']);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    expect(fn () => app(PurgeOrganisation::class)->handle($superAdmin, $active, 'still-active'))->toThrow(AuthorizationException::class);

    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    try {
        app(PurgeOrganisation::class)->handle($superAdmin, $fresh($organisation), 'ACME');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'confirmation' => [__('Type :slug to confirm.', ['slug' => 'acme'])],
        ]);
    }
    expect(Organisation::withTrashed()->find($organisation->id))->not->toBeNull();

    app(PurgeOrganisation::class)->handle($superAdmin, $fresh($organisation), 'acme');

    expect(Organisation::withTrashed()->find($organisation->id))->toBeNull()
        ->and(Organisation::query()->find($active->id))->not->toBeNull();
});

it('purges invitations already archived by the cascade, users incl. deleted ones, passkeys, sessions, team roles and pivot rows, then the organisation (ORG-11, C-16, RK-I10)', function () use ($signIn, $fresh, $templateRoleId): void {
    usePasskeysModule(true);
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $outsider = Organisation::factory()->create(['slug' => 'beta']);
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $admin->assignRole(Role::OrganisationAdmin->value));
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $editor->givePermissionTo(Permission::UsersView->value);
    $member = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, function () use ($member, $editor): void {
        $member->assignRole($editor);
        $member->givePermissionTo(Permission::UsersUpdate->value);
    });
    $deletedEarlier = User::factory()->for($organisation)->create(['deleted_at' => now()->subDay()]);
    $invitation = app(InviteUser::class)->handle($superAdmin, $organisation, 'invitee@example.com', [$templateRoleId()], Locale::English);
    $outsiderInvitation = app(InviteUser::class)->handle($superAdmin, $outsider, 'outsider@example.com', [$templateRoleId()], Locale::English);
    DB::table('passkeys')->insert([
        'user_id' => $member->id,
        'name' => 'Laptop',
        'credential_id' => 'credential-1',
        'credential' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    HandoffToken::query()->create([
        'user_id' => $member->id,
        'token_hash' => hash('sha256', 'handoff'),
        'target_host' => 'acme.localhost',
        'path' => '/dashboard',
        'expires_at' => now()->addMinute(),
    ]);
    // Another organisation's user with a direct permission, a session and a reset token: the purge leaves them.
    $outsiderUser = User::factory()->for($outsider)->create(['email' => 'outsider-user@example.com']);
    withPermissionsTeam($outsider->id, fn () => $outsiderUser->givePermissionTo(Permission::UsersView->value));
    $signIn($outsiderUser, 'outsider-token');
    DB::table('password_reset_tokens')->insert(['email' => 'outsider-user@example.com', 'token' => 'hashed-outsider', 'created_at' => now()]);
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    $signIn($member); // a session row written after the archive is purged as well

    app(PurgeOrganisation::class)->handle($superAdmin, $fresh($organisation), 'acme');

    $purgedIds = [$admin->id, $member->id, $deletedEarlier->id, $invitation->user_id];
    expect(DB::table('organisations')->where('id', $organisation->id)->exists())->toBeFalse()
        ->and(DB::table('users')->whereIn('id', $purgedIds)->exists())->toBeFalse()
        ->and(DB::table('invitations')->where('organisation_id', $organisation->id)->exists())->toBeFalse()
        ->and(DB::table('passkeys')->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(DB::table('handoff_tokens')->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->whereIn('user_id', $purgedIds)->exists())->toBeFalse()
        ->and(DB::table('roles')->where('team_id', $organisation->id)->exists())->toBeFalse()
        ->and(DB::table('role_has_permissions')->where('role_id', $editor->id)->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->where('team_id', $organisation->id)->exists())->toBeFalse()
        ->and(DB::table('model_has_permissions')->where('team_id', $organisation->id)->exists())->toBeFalse()
        ->and(RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->exists())->toBeTrue()
        // Bulk deletes filter on the organisation: another organisation's rows are untouched.
        ->and(DB::table('invitations')->where('id', $outsiderInvitation->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $outsiderInvitation->user_id)->exists())->toBeTrue()
        // The pivot deletes filter on the team or on the purged users' ids: other teams' assignments, the
        // super admin badges (null team) and other users' direct permissions stay.
        ->and(DB::table('model_has_roles')->where('team_id', $outsider->id)->where('model_id', $outsiderInvitation->user_id)->exists())->toBeTrue()
        ->and(DB::table('model_has_roles')->whereNull('team_id')->where('model_id', $superAdmin->id)->exists())->toBeTrue()
        ->and(DB::table('model_has_permissions')->where('team_id', $outsider->id)->where('model_id', $outsiderUser->id)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $outsiderUser->id)->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', 'outsider-user@example.com')->exists())->toBeTrue();
});

it('detaches promoted super admins, deleted ones included, instead of deleting them (DEC-26)', function () use ($signIn, $fresh): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $promotedDeleted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promotedDeleted, true);
    DB::table('users')->where('id', $promotedDeleted->id)->update(['deleted_at' => now()]);
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    $signIn($promoted, 'promoted-token');

    app(PurgeOrganisation::class)->handle($superAdmin, $fresh($organisation), 'acme');

    $rows = DB::table('users')->whereIn('id', [$promoted->id, $promotedDeleted->id])->orderBy('id')->get(['id', 'organisation_id', 'is_super_admin', 'deleted_at']);
    $detached = recordedActivity(ActivityEvent::OrganisationPurged)->sole()->properties?->get('detached_super_admins');
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('organisation_id')->all())->toBe([null, null])
        ->and($rows->map(fn (object $row): bool => (bool) $row->is_super_admin)->all())->toBe([true, true])
        ->and($rows->last()?->deleted_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $promoted->id)->exists())->toBeTrue()
        ->and($detached)->toBe([$promoted->id, $promotedDeleted->id]);
});

it('deletes the purged users\' password reset tokens and forgets the permission cache (CV-13)', function () use ($fresh): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for($organisation)->create(['email' => 'member@example.com']);
    User::factory()->for($organisation)->create(['email' => 'shared@example.com', 'deleted_at' => now()->subDay()]);
    User::factory()->create(['email' => 'shared@example.com']); // the address now belongs to a live account elsewhere
    DB::table('password_reset_tokens')->insert([
        ['email' => 'member@example.com', 'token' => 'hashed-1', 'created_at' => now()],
        ['email' => 'shared@example.com', 'token' => 'hashed-2', 'created_at' => now()],
    ]);
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();
    expect($registrar->getCacheRepository()->has($registrar->cacheKey))->toBeTrue();

    app(PurgeOrganisation::class)->handle($superAdmin, $fresh($organisation), 'acme');

    expect(DB::table('password_reset_tokens')->where('email', 'member@example.com')->exists())->toBeFalse()
        ->and(DB::table('password_reset_tokens')->where('email', 'shared@example.com')->exists())->toBeTrue()
        ->and($registrar->getCacheRepository()->has($registrar->cacheKey))->toBeFalse();
});

it('keeps the organisation\'s activity rows and records organisation.purged (ORG-12)', function () use ($fresh): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    User::factory()->for($organisation)->count(2)->create();
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);

    app(PurgeOrganisation::class)->handle($superAdmin, $fresh($organisation), 'acme');

    $purged = recordedActivity(ActivityEvent::OrganisationPurged)->sole();
    expect(recordedActivity(ActivityEvent::OrganisationArchived)->sole()->organisation_id)->toBe($organisation->id)
        ->and($purged->organisation_id)->toBe($organisation->id)
        ->and((int) $purged->causer_id)->toBe($superAdmin->id)
        ->and($purged->properties?->only(['name', 'slug', 'users'])->all())->toBe(['name' => 'Acme', 'slug' => 'acme', 'users' => 2]);
});

it('rolls everything back when the last step fails', function () use ($signIn, $fresh): void {
    $superAdmin = asSuperAdmin();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $role = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    withPermissionsTeam($organisation->id, fn () => $member->assignRole($role));
    app(ArchiveOrganisation::class)->handle($superAdmin, $organisation);
    $signIn($member);
    Activity::creating(function (Activity $activity): void {
        if ($activity->event === ActivityEvent::OrganisationPurged->value) {
            throw new RuntimeException('The audit log is unavailable.');
        }
    });

    expect(fn () => app(PurgeOrganisation::class)->handle($superAdmin, $fresh($organisation), 'acme'))->toThrow(RuntimeException::class);

    expect(Organisation::withTrashed()->find($organisation->id)?->trashed())->toBeTrue()
        ->and(DB::table('users')->where('id', $member->id)->exists())->toBeTrue()
        ->and((int) DB::table('users')->where('id', $promoted->id)->value('organisation_id'))->toBe($organisation->id)
        ->and(DB::table('sessions')->where('user_id', $member->id)->exists())->toBeTrue()
        ->and(DB::table('roles')->where('id', $role->id)->exists())->toBeTrue()
        ->and(DB::table('model_has_roles')->where('team_id', $organisation->id)->exists())->toBeTrue();
});

it('refuses organisation admins at every step', function () use ($fresh): void {
    $platformAdmin = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($platformAdmin, true);
    $own = Organisation::factory()->create(['slug' => 'own']);
    $archived = Organisation::factory()->create(['slug' => 'archived-one']);
    app(ArchiveOrganisation::class)->handle($platformAdmin, $archived);
    $admin = asOrgAdmin($own);
    onApex(); // the policies refuse, not the host rule

    expect(fn () => app(ArchiveOrganisation::class)->handle($admin, $own))->toThrow(AuthorizationException::class);
    expect(fn () => app(RestoreOrganisation::class)->handle($admin, $fresh($archived)))->toThrow(AuthorizationException::class);
    expect(fn () => app(PurgeOrganisation::class)->handle($admin, $fresh($archived), 'archived-one'))->toThrow(AuthorizationException::class);

    expect(Organisation::query()->find($own->id))->not->toBeNull()
        ->and($fresh($archived)->trashed())->toBeTrue()
        // The archive ran with nobody signed in: the causer is the actor passed in, not the web guard's user.
        ->and((int) recordedActivity(ActivityEvent::OrganisationArchived)->sole()->causer_id)->toBe($platformAdmin->id);
});

it('restores and purges only on a central host', function () use ($fresh): void {
    $superAdmin = asSuperAdmin();
    $archived = Organisation::factory()->create(['slug' => 'archived-one']);
    app(ArchiveOrganisation::class)->handle($superAdmin, $archived);
    onTenant(Organisation::factory()->create(['slug' => 'acme']));

    expect(fn () => app(RestoreOrganisation::class)->handle($superAdmin, $fresh($archived)))->toThrow(AuthorizationException::class);
    expect(fn () => app(PurgeOrganisation::class)->handle($superAdmin, $fresh($archived), 'archived-one'))->toThrow(AuthorizationException::class);
    expect($fresh($archived)->trashed())->toBeTrue();
});
