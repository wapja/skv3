<?php

declare(strict_types=1);

/** covers §6.6, §6.8 */

use App\Authorization\Role;
use App\Http\Middleware\EnsureSecondFactor;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SoftwareAuthenticator;

/** A signed-in member of acme, whose organisation requires a second factor; they have none yet. */
$memberUnderTheRule = fn (): User => asMember(Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]));

it('sends a member without a second factor from the dashboard to the security page, saying why (§6.6)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(true);
    $memberUnderTheRule();

    $this->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', __('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.'));
});

it('confirms the password on the way without a loop and explains the rule on the security page (§6.6)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(true);
    $memberUnderTheRule();

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk();
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee('data-test="second-factor-required"', escape: false)
        ->assertSee(__('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.'));
});

it('is satisfied by two-factor authentication set up on the security page, after which the dashboard opens (§6.6)', function () use ($memberUnderTheRule): void {
    $member = $memberUnderTheRule();
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.two-factor-setup-modal');

    // The setup runs as Livewire updates, which the rule lets through.
    callPageAction($snapshot, 'startTwoFactorSetup')->assertOk();

    // A secret whose setup is not confirmed is no second factor yet.
    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));

    $secret = (string) Fortify::currentEncrypter()->decrypt((string) $member->fresh()?->two_factor_secret);
    callPageAction($snapshot, 'confirmTwoFactor', updates: ['code' => (string) app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('security.edit'))->assertOk()->assertDontSee('data-test="second-factor-required"', escape: false);
});

it('is satisfied by a registered passkey, after which the dashboard opens (§6.6)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(true);
    $memberUnderTheRule();
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));

    /** @var array<string, mixed> $options */
    $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Laptop', 'credential' => new SoftwareAuthenticator()->attest($options, 'http://acme.localhost')])
        ->assertSuccessful();

    $this->get(route('dashboard'))->assertOk();
});

it('refuses the password alone to a user whose only second factor under the rule is a passkey, once the password is right (§6.6, DEC-35)', function (string $who): void {
    usePasskeysModule(true);
    config(['skv.second_factor.super_admins' => true]);
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $user = $who === 'a super admin' ? User::factory()->superAdmin()->create() : User::factory()->for($acme)->create();
    $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    onTenant($acme);

    // A wrong password gets the usual answer, so the refusal never tells what the password alone cannot.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'not-the-password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('Sign in with your passkey: a password alone is not enough for this account.')]);

    $this->assertGuest();
})->with(['a member', 'a super admin']);

it('still signs in with the password whoever the passkey rule does not cover (§6.6, §6.8)', function (string $case): void {
    usePasskeysModule($case !== 'passkeys off');
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => $case !== 'no rule']);
    $member = User::factory()->for($acme)->create();
    onTenant($acme);

    if ($case !== 'no factor yet') {
        $member->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    }

    if ($case === 'TOTP too') {
        $member->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    }

    $response = $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertSessionHasNoErrors();

    if ($case === 'TOTP too') {
        // Fortify asks for the code: the TOTP secret is the factor of this sign-in.
        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        return;
    }

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($member);
})->with([
    'no rule' => 'no rule',
    // The middleware then sends them to set one up.
    'no factor yet' => 'no factor yet',
    'TOTP too' => 'TOTP too',
    // A passkey signs nobody in with the module off, so it is no factor, and the middleware asks for TOTP.
    'passkeys off' => 'passkeys off',
]);

it('lets a passkey-only member under the rule in with the passkey itself (§6.6)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(true);
    $member = $memberUnderTheRule();
    $authenticator = new SoftwareAuthenticator;
    $this->withSession(['auth.password_confirmed_at' => time()]);

    /** @var array<string, mixed> $registration */
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Laptop', 'credential' => $authenticator->attest($registration, 'http://acme.localhost')])
        ->assertSuccessful();
    Auth::guard('web')->logout();

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();

    /** @var array<string, mixed> $options */
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, 'http://acme.localhost')])->assertOk();

    $this->assertAuthenticatedAs($member);
    $this->get(route('dashboard'))->assertOk();
});

it('keeps sending a user without a second factor back to the security page from every other page (§6.6)', function (string $page): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    // An organisation admin, so every page below would open without the rule.
    asOrgAdmin($acme);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(match ($page) {
        'organisations.edit' => route('organisations.edit', $acme->id),
        'settings' => url('settings'),
        default => route($page),
    })->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))->assertOk();
})->with(['dashboard', 'profile.edit', 'appearance.edit', 'settings', 'users.index', 'users.invite', 'invitations.pending', 'organisations.index', 'organisations.edit', 'roles.index', 'activity.index']);

it('refuses any other method with 403 (§6.6)', function (string $method) use ($memberUnderTheRule): void {
    $memberUnderTheRule();

    $this->call($method, url('settings'))->assertForbidden();
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('counts only two-factor authentication and says so while the passkeys module is off (§6.8)', function () use ($memberUnderTheRule): void {
    usePasskeysModule(false);
    $member = $memberUnderTheRule();
    // A passkey kept from before the module was switched off signs nobody in, so it is no second factor.
    $member->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $totpOnly = __('Your organisation requires a second factor. Set up two-factor authentication to continue.');

    $this->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', $totpOnly);
    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee($totpOnly)
        ->assertDontSee(__('Your organisation requires a second factor. Set up two-factor authentication or a passkey to continue.'));
});

it('applies REQUIRE_SECOND_FACTOR_SUPER_ADMINS to super admins on every host (§6.6, CD-4)', function (string $host, bool $required): void {
    usePasskeysModule(true);
    config(['skv.second_factor.super_admins' => $required]);
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = asSuperAdmin();
    match ($host) {
        'tenant' => onTenant($acme),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    if (! $required) {
        $this->get(route('organisations.index'))->assertOk();

        return;
    }

    $this->get(route('organisations.index'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', __('Super admins need a second factor. Set up two-factor authentication or a passkey to continue.'));

    $superAdmin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $this->get(route('organisations.index'))->assertOk();
})->with(['tenant', 'apex', 'admin'])->with(['required' => true, 'not required' => false]);

it('tells a super admin about a passkey only while the passkeys module is on, in the flash and the callout (§6.8, CD-4)', function (bool $passkeys): void {
    usePasskeysModule($passkeys);
    config(['skv.second_factor.super_admins' => true]);
    asSuperAdmin();
    onAdminHost();
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $withPasskey = __('Super admins need a second factor. Set up two-factor authentication or a passkey to continue.');
    $totpOnly = __('Super admins need a second factor. Set up two-factor authentication to continue.');

    $this->get(route('organisations.index'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', $passkeys ? $withPasskey : $totpOnly);
    // The first visit shows the flash; the second shows only the page's own callout.
    $this->get(route('security.edit'))->assertOk();
    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee('data-test="second-factor-required"', escape: false)
        ->assertSee($passkeys ? $withPasskey : $totpOnly)
        ->assertDontSee($passkeys ? $totpOnly : $withPasskey);
})->with(['passkeys on' => true, 'passkeys off' => false]);

it('lets an impersonation session through: the impersonator acts and cannot set up the target\'s factor (§6.6, CD-4)', function (): void {
    useImpersonationModule(true);
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $admin = User::factory()->for($acme)->create();
    withPermissionsTeam($acme->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $member = User::factory()->for($acme)->create();
    onTenant($acme);

    startImpersonatingOverHttp($admin, $member);

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('recognises Livewire\'s update, upload and preview requests by their route (CD-4)', function (string $name) use ($memberUnderTheRule): void {
    $member = $memberUnderTheRule();
    $route = app(Router::class)->getRoutes()->getByName($name) ?? throw new LogicException("No route [{$name}].");
    $request = Request::create('/'.$route->uri(), $route->methods()[0]);
    $request->setRouteResolver(fn (): Route => $route);
    $request->setUserResolver(fn (): User => $member);
    $request->setLaravelSession(app('session.store'));

    $response = app(EnsureSecondFactor::class)->handle($request, fn (): Response => response('passed'));

    expect($response->getContent())->toBe('passed');
})->with(['default-livewire.update', 'livewire.upload-file', 'livewire.preview-file']);

it('does not take a page request\'s X-Livewire header as a Livewire request (CD-4)', function () use ($memberUnderTheRule): void {
    $memberUnderTheRule();

    $this->get(route('dashboard'), ['X-Livewire' => '1'])->assertRedirect(route('security.edit'));
});

it('refuses a Livewire update from a page the rule now blocks (CD-4, plan review K7)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $member = User::factory()->for($acme)->create(['name' => 'Before']);
    $snapshot = pageSnapshot(route('users.edit', ['user' => $member->id]), 'pages::users.edit');

    $acme->forceFill(['require_second_factor' => true])->save();

    // The replayed persistent middleware answers with abort(redirect): check the exact status Livewire returns and
    // assert it (a redirect to security.edit); the point is that the action did not run.
    callPageAction($snapshot, 'saveAccount', updates: ['name' => 'After'])->assertRedirect(route('security.edit'));
    expect($member->fresh()?->name)->toBe('Before');
});

it('lets the configured exempt routes through, and only those (CD-4)', function () use ($memberUnderTheRule): void {
    $memberUnderTheRule();
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('profile.edit'))->assertRedirect(route('security.edit'));

    config(['skv.second_factor.exempt_routes' => ['security.edit', 'profile.*']]);

    $this->get(route('profile.edit'))->assertOk();
    $this->get(route('appearance.edit'))->assertRedirect(route('security.edit'));
});

it('exempts setting up a factor, the password confirmation, signing out, handoffs, ending an impersonation and setup by default (CD-4)', function (): void {
    expect(config('skv.second_factor.exempt_routes'))->toBe([
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'passkey.*',
        'well-known.passkeys',
        'logout',
        'auth.handoff',
        'auth.handoff.store',
        'impersonation.stop',
        'setup.*',
    ])->and((string) file_get_contents(base_path('.env.example')))->toContain("\nREQUIRE_SECOND_FACTOR_SUPER_ADMINS=false\n");
});

it('runs on every authenticated route that is not exempt, and not in the global web group (§6.6, CD-4)', function (): void {
    $router = app(Router::class);
    /** @var list<string> $exempt */
    $exempt = config('skv.second_factor.exempt_routes');
    $unguarded = [];

    foreach ($router->getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $authenticated = array_filter($middleware, fn (mixed $name): bool => $name === 'auth' || (is_string($name) && str_starts_with($name, 'auth:')));
        $name = $route->getName();

        // Livewire's own routes pass by name; the upload route is behind `auth` (config/livewire.php).
        if ($authenticated === [] || in_array(EnsureSecondFactor::class, $middleware, true) || ($name !== null && Str::is([...$exempt, ...EnsureSecondFactor::LIVEWIRE_ROUTES], $name))) {
            continue;
        }

        $unguarded[] = $name ?? $route->uri();
    }

    expect($unguarded)->toBe([])
        ->and($router->getRoutes()->getByName('dashboard')?->gatherMiddleware())->toContain(EnsureSecondFactor::class)
        ->and($router->getMiddlewareGroups()['web'])->not->toContain(EnsureSecondFactor::class);
});

it('never affects a member of an organisation without the rule (§6.6)', function (string $page): void {
    Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    asOrgAdmin($beta);

    $this->get($page === 'organisations.edit' ? route('organisations.edit', $beta->id) : route($page))->assertOk();
})->with(['dashboard', 'profile.edit', 'users.index', 'organisations.edit', 'activity.index']);
