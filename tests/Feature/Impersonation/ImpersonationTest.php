<?php

declare(strict_types=1);

/** covers §4.7, §6.4, §6.8 */

use App\Actions\Audit\RecordActivity;
use App\Actions\Auth\IssueHandoff;
use App\Actions\Users\StartImpersonation;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Hosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withSession;

beforeEach(function (): void {
    useImpersonationModule(true);
});

/** A user of $organisation holding the organisation_admin template role (DEC-21), not signed in. */
$organisationAdmin = function (Organisation $organisation): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');

    return $user;
};

it('requires a reason that is not blank and at most 500 characters (IMP-1)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $admin = $organisationAdmin($organisation);
    actingAs($admin);
    onTenant($organisation);

    foreach (['', '   ', str_repeat('a', 501)] as $reason) {
        expect(fn () => app(StartImpersonation::class)->handle($admin, $member, $reason))
            ->toThrow(ValidationException::class);
    }

    expect(Impersonation::isActive())->toBeFalse();

    app(StartImpersonation::class)->handle($admin, $member, '  '.str_repeat('a', 500).'  ');

    expect(Impersonation::isActive())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::ImpersonationStarted)->sole()->getProperty('reason'))->toBe(str_repeat('a', 500));
});

it('refuses a disallowed start and leaves the session as it was (IMP-3, IMP-4, IMP-5, IMP-11)', function (string $case, string $exception) use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $admin = $organisationAdmin($organisation);
    $roleWithoutImpersonate = RoleModel::query()->create(['name' => 'helpdesk-'.Str::lower(Str::random(6)), 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $roleWithoutImpersonate->givePermissionTo([Permission::UsersView->value, Permission::UsersUpdate->value]);
    $helpdesk = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $helpdesk->assignRole($roleWithoutImpersonate));
    $helpdesk->unsetRelation('roles');
    $promotedSuperAdmin = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promotedSuperAdmin, true);
    $promotedSuperAdmin->refresh();
    onTenant($organisation);

    [$signedIn, $actor, $target] = match ($case) {
        'member → organisation admin' => [$member, $member, $admin],
        'organisation admin → another organisation admin' => [$admin, $admin, $organisationAdmin($organisation)],
        'organisation admin → super admin' => [$admin, $admin, $promotedSuperAdmin],
        'actor without users.impersonate → member' => [$helpdesk, $helpdesk, $member],
        'module off' => [$admin, $admin, $member],
        'second start while impersonating' => [$member, $admin, User::factory()->for($organisation)->create()],
        'an actor who is not the signed-in user' => [$helpdesk, $admin, $member],
        default => throw new LogicException("Unknown case [{$case}]."),
    };

    actingAs($signedIn);

    if ($case === 'module off') {
        useImpersonationModule(false);
    }

    if ($case === 'second start while impersonating') {
        actingAs($admin);
        app(StartImpersonation::class)->handle($admin, $member, 'First ticket');
    }

    expect(fn () => app(StartImpersonation::class)->handle($actor, $target, 'Support ticket'))->toThrow($exception)
        ->and(Auth::guard('web')->id())->toBe($signedIn->id);

    if ($case === 'second start while impersonating') {
        expect(Impersonation::impersonatorId())->toBe($admin->id)
            ->and(Impersonation::targetId())->toBe($member->id)
            ->and(recordedActivity(ActivityEvent::ImpersonationStarted)->sole()->subject_id)->toBe($member->id)
            // The swap itself refuses a second level too, whatever its caller authorised (depth 1).
            ->and(fn () => Impersonation::enter($admin, $target))->toThrow(LogicException::class)
            ->and(Impersonation::impersonatorId())->toBe($admin->id)
            ->and(Auth::guard('web')->id())->toBe($member->id);

        return;
    }

    expect(Impersonation::isActive())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::ImpersonationStarted))->toBeEmpty();
})->with([
    ['member → organisation admin', AuthorizationException::class],
    ['organisation admin → another organisation admin', AuthorizationException::class],
    ['organisation admin → super admin', AuthorizationException::class],
    ['actor without users.impersonate → member', AuthorizationException::class],
    ['module off', AuthorizationException::class],
    ['second start while impersonating', AuthorizationException::class],
    ['an actor who is not the signed-in user', LogicException::class],
]);

it('swaps to the target and back while the session stays valid across requests', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    startImpersonatingOverHttp($admin, $member);

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($member);

    // A second request as the target: AuthenticateSession accepted the swapped password hash.
    Auth::guard('web')->forgetUser();
    $this->get(route('profile.edit'))->assertOk();
    $this->assertAuthenticatedAs($member);

    Auth::guard('web')->forgetUser();
    $this->delete(route('impersonation.stop'))->assertRedirect(route('users.index'));

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(Impersonation::isActive())->toBeFalse();
});

it('records impersonation.start with the reason and impersonation.stop, caused by the real user with impersonated_as (IMP-6)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    startImpersonatingOverHttp($admin, $member, 'Ticket 42');
    $this->delete(route('impersonation.stop'));

    $start = recordedActivity(ActivityEvent::ImpersonationStarted)->sole();
    $stop = recordedActivity(ActivityEvent::ImpersonationStopped)->sole();

    expect($start->causer_id)->toBe($admin->id)
        ->and($start->subject_id)->toBe($member->id)
        ->and($start->organisation_id)->toBe($organisation->id)
        ->and($start->getProperty('reason'))->toBe('Ticket 42')
        ->and($start->getProperty('impersonated_as'))->toBe($member->id)
        ->and($stop->causer_id)->toBe($admin->id)
        ->and($stop->subject_id)->toBe($member->id)
        ->and($stop->organisation_id)->toBe($organisation->id)
        ->and($stop->getProperty('impersonated_as'))->toBe($member->id)
        ->and($stop->getProperty('ended_because'))->toBeNull();
});

it('rewrites the causer of anything logged while impersonating', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    Route::middleware(['web', 'auth'])->post('_record-activity', function (RecordActivity $recordActivity): string {
        $recordActivity->handle(ActivityEvent::PasswordChanged, Auth::user());

        return 'recorded';
    });

    startImpersonatingOverHttp($admin, $member);
    $this->post('/_record-activity')->assertOk();

    $entry = recordedActivity(ActivityEvent::PasswordChanged)->sole();

    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->getProperty('impersonated_as'))->toBe($member->id);
});

it('forgets the password confirmation on start and stop', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    withSession(['auth.password_confirmed_at' => time()]);

    startImpersonatingOverHttp($admin, $member);

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    session()->put('auth.password_confirmed_at', time());
    $this->delete(route('impersonation.stop'));

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('keeps the target\'s confirmation-gated settings actions closed while impersonating (RK-D7)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    // The impersonator confirmed their own password just before starting.
    withSession(['auth.password_confirmed_at' => time()]);

    startImpersonatingOverHttp($admin, $member);

    $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

it('returns an impersonator without users.view to the dashboard after stopping (OR-M6)', function (): void {
    $organisation = Organisation::factory()->create();
    $role = RoleModel::query()->create(['name' => 'support-'.Str::lower(Str::random(6)), 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo(Permission::UsersImpersonate->value);
    $support = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $support->assignRole($role));
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    startImpersonatingOverHttp($support, $member);

    $this->delete(route('impersonation.stop'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', __('You are back in your own account.'));
});

it('refuses a handoff while impersonating', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    startImpersonatingOverHttp($admin, $member);

    expect(fn () => app(IssueHandoff::class)->handle($member, Hosts::tenant($organisation->slug)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(IssueHandoff::class)->handle($admin, Hosts::tenant($organisation->slug)))
        ->toThrow(AuthorizationException::class);
});

it('clears impersonation state on a real sign-in', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    expect(Impersonation::isActive())->toBeTrue();

    Auth::guard('web')->login($admin);

    expect(Impersonation::isActive())->toBeFalse();
});

it('answers 404 for DELETE /impersonation and has no impersonation.stop route when the module is off (RK-I12)', function (): void {
    $organisation = Organisation::factory()->create();
    asMember($organisation);

    useImpersonationModule(false);

    expect(Route::has('impersonation.stop'))->toBeFalse();
    $this->delete('/impersonation')->assertNotFound();

    // The route can outlive the flag (a cached route file): the controller still answers 404.
    useImpersonationModule(true);
    config(['skv.modules.impersonation' => false]);

    expect(Route::has('impersonation.stop'))->toBeTrue();
    $this->delete('/impersonation')->assertNotFound();
});
