<?php

declare(strict_types=1);

/** covers §4.4, §4.7, §6.2 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Livewire\Actions\Logout;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

it('shows the action only on tenant hosts and only for targets the actor may impersonate', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $otherAdmin = $organisationAdmin($organisation);
    $admin = asOrgAdmin($organisation);

    $this->get(route('users.index'))->assertOk()
        ->assertSee('data-test="impersonate-'.$member->id.'"', false)
        ->assertDontSee('data-test="impersonate-'.$otherAdmin->id.'"', false)
        ->assertDontSee('data-test="impersonate-'.$admin->id.'"', false);

    asSuperAdmin();
    onAdminHost();

    $this->get(route('users.index'))->assertOk()
        ->assertSee($member->email)
        ->assertDontSee('data-test="impersonate-', false);
});

it('starts from the users index with a reason and lands on the dashboard as the target', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect();
    Auth::guard('web')->forgetUser();

    $snapshot = pageSnapshot(route('users.index'), 'pages::users.index');
    $confirmed = callPageAction($snapshot, 'confirmImpersonation', [$member->id])->assertOk();

    /** @var array<string, mixed> $afterConfirm */
    $afterConfirm = json_decode((string) $confirmed->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    Auth::guard('web')->forgetUser();
    callPageAction($afterConfirm, 'startImpersonation', [], ['impersonationReason' => '   '])->assertOk();
    expect(Impersonation::isActive())->toBeFalse();

    Auth::guard('web')->forgetUser();
    callPageAction($afterConfirm, 'startImpersonation', [], ['impersonationReason' => 'Ticket 42'])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('dashboard'));

    Auth::guard('web')->forgetUser();
    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($member);
    expect(recordedActivity(ActivityEvent::ImpersonationStarted)->sole()->getProperty('reason'))->toBe('Ticket 42');
});

it('refuses a forged start from the apex and the admin host with 404 (TEN-3)', function (string $host): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asSuperAdmin();

    if ($host === 'apex') {
        onApex();
    } else {
        onAdminHost();
    }

    // The forged updates go to the current origin, the central host.
    $snapshot = pageSnapshot(route('users.index'), 'pages::users.index');

    callPageAction($snapshot, 'startImpersonation', [], ['impersonationReason' => 'Ticket 42'])->assertNotFound();
    callPageAction($snapshot, 'confirmImpersonation', [$member->id])->assertForbidden();
    expect(Impersonation::isActive())->toBeFalse();
})->with(['apex', 'admin']);

it('shows the sticky banner with a stop button while impersonating', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('data-test="impersonation-banner"', false)
        ->assertSee(__('Logged in as :name', ['name' => $member->name]))
        ->assertSee('action="'.route('impersonation.stop').'"', false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertSee('data-test="stop-impersonating"', false);

    Auth::guard('web')->forgetUser();
    $this->delete(route('impersonation.stop'));
    Auth::guard('web')->forgetUser();

    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-test="impersonation-banner"', false);
});

it('stops through DELETE /impersonation and shows the "stopped" toast (IMP-7, X3)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    $this->delete(route('impersonation.stop'))
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('status', __('You are back in your own account.'));

    Auth::guard('web')->forgetUser();
    // Task 12's bridge turns the flash into a Flux toast on the next app page.
    $this->get(route('users.index'))->assertOk()
        ->assertSee('data-flash-toast', false)
        ->assertSee(__('You are back in your own account.'));
    $this->assertAuthenticatedAs($admin);
});

it('stops the impersonation before signing out and leaves the target\'s remember token alone', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    $rememberToken = DB::table('users')->where('id', $member->id)->value('remember_token');
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    $this->post(route('logout'))->assertRedirect();

    $this->assertGuest();
    expect(Impersonation::isActive())->toBeFalse()
        ->and(DB::table('users')->where('id', $member->id)->value('remember_token'))->toBe($rememberToken)
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('logout');
});

it('stops the impersonation in the Logout action too (RK-M5)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    $rememberToken = DB::table('users')->where('id', $member->id)->value('remember_token');
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    app(Logout::class)();

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Impersonation::isActive())->toBeFalse()
        ->and(DB::table('users')->where('id', $member->id)->value('remember_token'))->toBe($rememberToken)
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('logout');
});
