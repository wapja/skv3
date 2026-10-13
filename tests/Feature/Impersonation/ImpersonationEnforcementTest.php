<?php

declare(strict_types=1);

/** covers §4.1, §4.7, §6.4 */

use App\Actions\Auth\IssueHandoff;
use App\Actions\Users\UpdateUserStatus;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Hosts;
use App\Tenancy\Http\Middleware\EnforceImpersonation;
use App\Tenancy\Http\Middleware\InitializeTenancy;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Fixtures\CsrfEnforcingPreventRequestForgery;

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

it('re-checks the impersonation before anything resolves the signed-in user (RL-20)', function (): void {
    app(HttpKernel::class); // syncs the middleware groups and priority to the router
    $route = Route::getRoutes()->getByName('dashboard') ?? throw new LogicException('No dashboard route.');
    $middleware = array_values(array_map(
        fn (mixed $entry): string => is_string($entry) ? Str::before($entry, ':') : 'closure',
        app(Router::class)->gatherRouteMiddleware($route),
    ));
    // Class name => position in the stack; the four classes checked each occur once.
    $position = array_flip($middleware);

    expect($middleware)->toContain(EnforceImpersonation::class, Authenticate::class, StartSession::class, InitializeTenancy::class)
        ->and($position[InitializeTenancy::class])->toBeLessThan($position[EnforceImpersonation::class])
        ->and($position[StartSession::class])->toBeLessThan($position[EnforceImpersonation::class])
        ->and($position[EnforceImpersonation::class])->toBeLessThan($position[Authenticate::class]);
});

it('ends the impersonation cleanly and logs the stop when the target is disabled meanwhile (W3)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    DB::table('users')->where('id', $member->id)->update(['status' => UserStatus::Disabled->value]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('status', __('The impersonation has ended. You are back in your own account.'));

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(Impersonation::isActive())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('target_unavailable');
});

it('ends it and restores the impersonator when the target is deleted meanwhile, without a silent remember-cookie sign-in (RK-I1)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    DB::table('users')->where('id', $member->id)->update(['deleted_at' => now()]);
    // The impersonator's browser still holds a valid remember-me cookie: once the session's user (the
    // deleted target) cannot be loaded, the guard would fall back to it and sign the impersonator in
    // silently, firing Login and skipping the logged stop.
    $guard = Auth::guard('web');
    $this->withCookie(
        $guard->getRecallerName(),
        $admin->id.'|'.$admin->remember_token.'|'.$guard->hashPasswordForCookie($admin->password),
    );

    $this->get(route('dashboard'))->assertRedirect(route('users.index'));

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(Impersonation::isActive())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::Login))->toHaveCount(1) // only the sign-in through the login route
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('target_unavailable');
});

it('renders an error page raised before the re-check without resolving the user, so a gone target never falls back to the remember cookie (RL-20, RK-I1)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    // The CSRF middleware sits ahead of EnforceImpersonation and skips itself in unit tests; this subclass
    // turns that off, so its 419 page renders before the impersonation is re-checked.
    $this->app->bind(PreventRequestForgery::class, CsrfEnforcingPreventRequestForgery::class);
    Route::middleware('web')->post('_csrf-probe', fn (): string => 'ok');

    DB::table('users')->where('id', $member->id)->update(['deleted_at' => now()]);
    $guard = Auth::guard('web');
    $this->withCookie(
        $guard->getRecallerName(),
        $admin->id.'|'.$admin->remember_token.'|'.$guard->hashPasswordForCookie($admin->password),
    );

    $this->post('/_csrf-probe')->assertStatus(419);

    expect(Impersonation::isActive())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::Login))->toHaveCount(1) // only the sign-in through the login route
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped))->toBeEmpty();

    Auth::guard('web')->forgetUser();
    $this->get(route('dashboard'))->assertRedirect(route('users.index'));

    expect(Impersonation::isActive())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::Login))->toHaveCount(1)
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('target_unavailable');
});

it('keeps the impersonation session when disabling the target cuts their sessions, so the stop is logged and no remember cookie signs anyone in (RL-28, RK-I1)', function () use ($organisationAdmin): void {
    // The kit default; phpunit.xml uses the array driver, which has no sessions.user_id to cut. A store or
    // guard resolved before this line would keep the array handler, so both are rebuilt.
    config(['session.driver' => 'database']);
    app(SessionManager::class)->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();

    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);
    $sessionId = session()->getId();

    // The impersonation session's row names the real person, not the impersonated user, so Sessions::endFor()
    // (a plain delete by sessions.user_id) on the target leaves it alone.
    expect(DB::table('sessions')->where('id', $sessionId)->value('user_id'))->toBe($admin->id);

    onTenant($organisation);
    app(UpdateUserStatus::class)->handle($admin, $member, UserStatus::Disabled);

    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeTrue();

    // A new request from the impersonator's browser: only its session cookie and its valid remember-me cookie,
    // nothing kept in memory, so the session is read back from the sessions table.
    $guard = Auth::guard('web');
    $rememberCookie = $admin->id.'|'.$admin->remember_token.'|'.$guard->hashPasswordForCookie($admin->password);
    Auth::forgetGuards();
    $this->flushSession();
    $this->withCookies([session()->getName() => $sessionId, $guard->getRecallerName() => $rememberCookie]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('status', __('The impersonation has ended. You are back in your own account.'));

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(Impersonation::isActive())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::Login))->toHaveCount(1) // only the sign-in through the login route
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('target_unavailable');
});

it('signs out when the impersonator is disabled and leaves the target\'s remember token alone (RK-I1)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    $rememberToken = DB::table('users')->where('id', $member->id)->value('remember_token');
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    DB::table('users')->where('id', $admin->id)->update(['status' => UserStatus::Disabled->value]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('The impersonation has ended. Sign in again.'));

    $this->assertGuest();
    expect(Impersonation::isActive())->toBeFalse()
        ->and(DB::table('users')->where('id', $member->id)->value('remember_token'))->toBe($rememberToken)
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('impersonator_unavailable')
        // The logged stop says it all; the target never signed in, so no sign-out is recorded for them.
        ->and(recordedActivity(ActivityEvent::Logout))->toBeEmpty();
});

it('signs out and logs impersonator_credentials_changed when the impersonator\'s password changed (RK-B4)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    DB::table('users')->where('id', $admin->id)->update(['password' => Hash::make('changed-elsewhere')]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
    $stop = recordedActivity(ActivityEvent::ImpersonationStopped)->sole();
    expect($stop->getProperty('ended_because'))->toBe('impersonator_credentials_changed')
        ->and($stop->causer_id)->toBe($admin->id);
});

it('ends it with a logged stop when the target\'s password changed meanwhile, before AuthenticateSession signs the browser out', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    DB::table('users')->where('id', $member->id)->update(['password' => Hash::make('changed-password')]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('status', __('The impersonation has ended. You are back in your own account.'));

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(Impersonation::isActive())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('target_credentials_changed');
});

it('ends it when the target is promoted to organisation admin meanwhile (RK-I2)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    withPermissionsTeam($organisation->id, fn (): User => $member->assignRole(Role::OrganisationAdmin->value));

    $this->get(route('dashboard'))->assertRedirect(route('users.index'));

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('no_longer_allowed');
});

it('ends it when the module is turned off meanwhile (RK-I3)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    config(['skv.modules.impersonation' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('users.index'));

    Auth::guard('web')->forgetUser();
    $this->get(route('users.index'))->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(Impersonation::isActive())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('module_disabled');
});

it('stops a running impersonation before consuming a handoff and keeps the target\'s remember token (RK-I1)', function (string $owner) use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    $rememberToken = DB::table('users')->where('id', $member->id)->value('remember_token');
    onTenant($organisation);
    $linkOwner = $owner === 'impersonator' ? $admin : $member;
    // Issued before the impersonation starts: no handoff is issued while impersonating.
    $url = app(IssueHandoff::class)->handle($linkOwner, Hosts::tenant($organisation->slug));
    startImpersonatingOverHttp($admin, $member);

    // The confirmation page, also for the target's own link: never the same-user shortcut while impersonating.
    $this->get($url)->assertOk();
    expect(HandoffToken::query()->whereNull('consumed_at')->count())->toBe(1);

    Auth::guard('web')->forgetUser();
    $this->post(route('auth.handoff.store', ['token' => Str::afterLast($url, '/')]), ['_token' => session()->token()])
        ->assertRedirect();

    Auth::guard('web')->forgetUser();
    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($linkOwner);
    expect(Impersonation::isActive())->toBeFalse()
        ->and(DB::table('users')->where('id', $member->id)->value('remember_token'))->toBe($rememberToken)
        ->and(recordedActivity(ActivityEvent::ImpersonationStopped)->sole()->getProperty('ended_because'))->toBe('handoff');
})->with(['impersonator', 'target']);

it('logs the stop without a causer when the impersonator no longer exists (RK-M6)', function () use ($organisationAdmin): void {
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    startImpersonatingOverHttp($admin, $member);

    DB::table('users')->where('id', $admin->id)->update(['deleted_at' => now()]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
    $stop = recordedActivity(ActivityEvent::ImpersonationStopped)->sole();
    expect($stop->causer_id)->toBeNull()
        ->and($stop->getProperty('impersonator_id'))->toBe($admin->id)
        ->and($stop->getProperty('impersonated_as'))->toBe($member->id)
        ->and($stop->getProperty('ended_because'))->toBe('impersonator_unavailable');
});
