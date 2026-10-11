<?php

declare(strict_types=1);

/** covers §4.4, §4.6 */

use App\Actions\Users\DeleteUser;
use App\Actions\Users\RestoreUser;
use App\Actions\Users\UpdateUserStatus;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * The validation errors an action throws, or [] when it succeeds.
 *
 * @return array<string, array<int, string>>
 */
$errorsOf = function (Closure $action): array {
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
};

$adminOf = function (Organisation $organisation, User $user): User {
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');

    return $user;
};

it('refuses to disable or delete the last active super admin, even when another super admin asks (W2)', function () use ($errorsOf): void {
    $disabledSuperAdmin = User::factory()->superAdmin()->disabled()->create();
    $onlyActive = User::factory()->superAdmin()->create();
    onAdminHost();

    expect($errorsOf(fn () => app(UpdateUserStatus::class)->handle($disabledSuperAdmin, $onlyActive, UserStatus::Disabled)))
        ->toBe(['status' => [__('At least one active super admin must remain.')]])
        ->and($errorsOf(fn () => app(DeleteUser::class)->handle($disabledSuperAdmin, $onlyActive)))
        ->toBe(['user' => [__('At least one active super admin must remain.')]])
        ->and($onlyActive->refresh()->isActive())->toBeTrue()
        ->and($onlyActive->trashed())->toBeFalse();

    User::factory()->superAdmin()->create();

    app(DeleteUser::class)->handle($disabledSuperAdmin, $onlyActive);

    expect($onlyActive->refresh()->trashed())->toBeTrue();
});

it('refuses to disable or delete the last active organisation admin, also for super admins (W12)', function () use ($errorsOf, $adminOf): void {
    $organisation = Organisation::factory()->create();
    $onlyAdmin = $adminOf($organisation, User::factory()->for($organisation)->create());
    $adminOf($organisation, User::factory()->for($organisation)->disabled()->create());
    $superAdmin = asSuperAdmin();
    onAdminHost();

    expect($errorsOf(fn () => app(UpdateUserStatus::class)->handle($superAdmin, $onlyAdmin, UserStatus::Disabled)))
        ->toBe(['status' => [__('An organisation needs at least one active organisation admin.')]])
        ->and($errorsOf(fn () => app(DeleteUser::class)->handle($superAdmin, $onlyAdmin)))
        ->toBe(['user' => [__('An organisation needs at least one active organisation admin.')]])
        ->and($onlyAdmin->refresh()->isActive())->toBeTrue()
        ->and($onlyAdmin->trashed())->toBeFalse();

    $adminOf($organisation, User::factory()->for($organisation)->create());

    app(UpdateUserStatus::class)->handle($superAdmin, $onlyAdmin, UserStatus::Disabled);

    expect($onlyAdmin->refresh()->status)->toBe(UserStatus::Disabled);
});

it('decides on the stored row, not on a stale model (FU2-5)', function () use ($errorsOf, $adminOf): void {
    $organisation = Organisation::factory()->create();
    $first = $adminOf($organisation, User::factory()->for($organisation)->create());
    $second = $adminOf($organisation, User::factory()->for($organisation)->disabled()->create());
    // Loaded while the second admin was still disabled.
    $stale = $second->fresh() ?? throw new LogicException('The second admin is missing.');
    $superAdmin = asSuperAdmin();
    onAdminHost();

    // Meanwhile another request enabled the second admin and disabled the first, so the second is now the
    // only active organisation admin, while the stale model still says "disabled". An action that read the
    // model would skip the lock-out guard and delete the last active admin.
    DB::table('users')->where('id', $second->id)->update(['status' => UserStatus::Active->value]);
    DB::table('users')->where('id', $first->id)->update(['status' => UserStatus::Disabled->value]);

    expect($errorsOf(fn () => app(DeleteUser::class)->handle($superAdmin, $stale)))
        ->toBe(['user' => [__('An organisation needs at least one active organisation admin.')]])
        ->and($second->refresh()->trashed())->toBeFalse()
        ->and($second->status)->toBe(UserStatus::Active)
        ->and(recordedActivity(ActivityEvent::UserDeleted))->toBeEmpty();
});

it('refuses to disable or delete yourself (W2, W12)', function () use ($adminOf): void {
    $organisation = Organisation::factory()->create();
    // Another active super admin and organisation admin exist, so only the policy can refuse.
    User::factory()->superAdmin()->create();
    $adminOf($organisation, User::factory()->for($organisation)->create());
    $superAdmin = asSuperAdmin();
    $admin = asOrgAdmin($organisation);

    foreach ([$admin, $superAdmin] as $actor) {
        expect(fn () => app(UpdateUserStatus::class)->handle($actor, $actor, UserStatus::Disabled))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteUser::class)->handle($actor, $actor))->toThrow(AuthorizationException::class)
            ->and($actor->refresh()->isActive())->toBeTrue()
            ->and($actor->trashed())->toBeFalse();
    }
});

it('never sets pending activation and never changes a pending user\'s status (W4, USR-13)', function () use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $pending = User::factory()->for($organisation)->pending()->create();
    $admin = asOrgAdmin($organisation);

    expect($errorsOf(fn () => app(UpdateUserStatus::class)->handle($admin, $member, UserStatus::PendingActivation)))
        ->toBe(['status' => [__('Pending activation can\'t be set by hand.')]]);

    foreach ([UserStatus::Active, UserStatus::Disabled] as $status) {
        expect($errorsOf(fn () => app(UpdateUserStatus::class)->handle($admin, $pending, $status)))
            ->toBe(['status' => [__('A pending user becomes active by accepting the invitation.')]]);
    }

    expect($member->refresh()->status)->toBe(UserStatus::Active)
        ->and($pending->refresh()->status)->toBe(UserStatus::PendingActivation);
});

it('refuses to delete a pending user and points to the invitation', function () use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $pending = User::factory()->for($organisation)->pending()->create();
    $admin = asOrgAdmin($organisation);

    expect($errorsOf(fn () => app(DeleteUser::class)->handle($admin, $pending)))
        ->toBe(['user' => [__('Cancel the invitation instead.')]])
        ->and($pending->refresh()->trashed())->toBeFalse();
});

it('restores a deleted member and records it', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);
    app(DeleteUser::class)->handle($admin, $member);

    app(RestoreUser::class)->handle($admin, $member);

    $entry = recordedActivity(ActivityEvent::UserRestored)->sole();
    expect($member->refresh()->trashed())->toBeFalse()
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->organisation_id)->toBe($organisation->id);
});

it('refuses to restore while the organisation is archived, when the address is taken again, or for a cancelled invitation\'s user (USR-11, C-9)', function (string $case) use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $deleted = $case === 'cancelled invitation'
        ? User::factory()->for($organisation)->pending()->create(['email' => 'back@example.com'])
        : User::factory()->for($organisation)->create(['email' => 'back@example.com']);
    $deleted->delete();

    if ($case === 'archived organisation') {
        $organisation->delete();
    }

    if ($case === 'address taken') {
        User::factory()->for($organisation)->create(['email' => 'back@example.com']);
    }

    $superAdmin = asSuperAdmin();
    onAdminHost();
    $expected = match ($case) {
        'archived organisation' => ['user' => [__('Restore the organisation first.')]],
        'address taken' => ['email' => [__('This address is already used in this organisation.')]],
        default => ['user' => [__('This user never accepted their invitation. Invite the address again.')]],
    };

    expect($errorsOf(fn () => app(RestoreUser::class)->handle($superAdmin, $deleted)))->toBe($expected)
        ->and($deleted->refresh()->trashed())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::UserRestored))->toBeEmpty();
})->with(['archived organisation', 'address taken', 'cancelled invitation']);

it('cuts the sessions and remember token of a disabled or deleted user, so re-enabling or restoring revives neither (RL-28)', function (string $lockOut): void {
    // The database session driver (the kit default), set before anything resolves the session or the
    // guard, so the guard and the session middleware share one database-backed store.
    config(['session.driver' => 'database']);
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers();
    app()->forgetInstance('auth.driver');
    Auth::forgetGuards();

    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['remember_token' => 'old-remember-token']);
    $colleague = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);

    // A colleague's signed-in browser, which the lock-out leaves alone.
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $colleague->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => time(),
    ]);

    $guard = Auth::guard('web');
    $sessionCookie = (string) config('session.cookie');
    $recallerCookie = $guard->getRecallerName();
    $rememberCookie = [$recallerCookie => $member->id.'|old-remember-token|'.$guard->hashPasswordForCookie($member->password)];

    /**
     * A new browser visit: no guard state, no session attributes and only the given cookies. Both cookie
     * names are always sent, an empty value meaning "no cookie", so nothing carries over from the last visit.
     *
     * @param  array<string, string>  $cookies
     */
    $newBrowserVisit = function (array $cookies) use ($sessionCookie, $recallerCookie): TestResponse {
        Auth::forgetGuards();
        app()->forgetInstance('auth.driver');
        $this->flushSession();

        return $this->withCookies(array_replace([$sessionCookie => '', $recallerCookie => ''], $cookies))
            ->get(route('dashboard'));
    };

    // Before the lock-out the remember cookie signs the member in, and the database driver stores that
    // session under the member's id.
    $newBrowserVisit($rememberCookie)->assertOk();
    $this->assertAuthenticatedAs($member);
    $sessionId = app('session')->driver()->getId();
    expect(DB::table('sessions')->where('id', $sessionId)->value('user_id'))->toBe($member->id);

    // The session cookie alone signs the member in too.
    $newBrowserVisit([$sessionCookie => $sessionId])->assertOk();
    $this->assertAuthenticatedAs($member);

    if ($lockOut === 'disable') {
        app(UpdateUserStatus::class)->handle($admin, $member, UserStatus::Disabled);
    } else {
        app(DeleteUser::class)->handle($admin, $member);
    }

    expect(DB::table('sessions')->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $colleague->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $member->id)->value('remember_token'))->toBeNull();

    if ($lockOut === 'disable') {
        app(UpdateUserStatus::class)->handle($admin, $member->refresh(), UserStatus::Active);
    } else {
        app(RestoreUser::class)->handle($admin, $member->refresh());
    }

    // After re-enabling or restoring, neither the old session cookie nor the old remember cookie signs in.
    $newBrowserVisit([$sessionCookie => $sessionId])->assertRedirect(route('login'));
    $this->assertGuest();

    $newBrowserVisit($rememberCookie)->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['disable', 'delete']);

it('records status changes and deletions', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);

    app(UpdateUserStatus::class)->handle($admin, $member, UserStatus::Disabled);
    app(UpdateUserStatus::class)->handle($admin, $member, UserStatus::Disabled);
    app(DeleteUser::class)->handle($admin, $member);

    $statusChange = recordedActivity(ActivityEvent::UserStatusChanged)->sole();
    $deletion = recordedActivity(ActivityEvent::UserDeleted)->sole();

    expect($statusChange->subject_id)->toBe($member->id)
        ->and($statusChange->causer_id)->toBe($admin->id)
        ->and($statusChange->organisation_id)->toBe($organisation->id)
        ->and($statusChange->attribute_changes?->toArray())->toEqual(['old' => ['status' => 'active'], 'attributes' => ['status' => 'disabled']])
        ->and($deletion->subject_id)->toBe($member->id)
        ->and($deletion->causer_id)->toBe($admin->id)
        ->and($deletion->organisation_id)->toBe($organisation->id);
});
