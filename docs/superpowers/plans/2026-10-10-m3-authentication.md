# M3: Authentication — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make authentication host-aware and safe across the multi-tenant hosts: one login rule for password, two-factor and passkeys, failure-only throttling, passkeys that work on every subdomain, password reset and email change on the user's own host, session invalidation, and a single-use cross-host handoff.

**Architecture:** A `HostLoginRule` (active, not deleted, belongs to the current host) is the single gate, wired into Fortify's `authenticateUsing` callback (Timebox, memoised), the two-factor request and `Passkeys::authorizeLoginUsing()`. Rate limiters count only failed attempts through `Limit::after()`. Passkeys use the apex as relying-party id and get the request origin as their allowed origin per request. Links that leave the current request (password reset, email change, handoff) are built for the user's home host via `Hosts::homeOf()`. A `handoff_tokens` table makes cross-host sign-in single-use and host-bound.

**Tech Stack:** PHP 8.4, Laravel 13.35, Livewire 4.4, Flux 2, Fortify 1.41, laravel/passkeys 0.2.1 (web-auth/webauthn-lib 5.3), Pest 5.3, Larastan 3 level 8, PostgreSQL 18 (Herd), SQLite.

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md` — read §4.2, §4.10 (settings), §6.2 (tenancy), §6.4 (handoff), §6.8 (passkeys module, deferred), §7.2, §11.2 (M3 row) and Appendix A (P1, P2, R1, E1) before starting. Decisions: `docs/decisions.md` DEC-19 (passkeys pairing), DEC-25 (M3 follow-ups), DEC-26 (M2 rulings).

## Global Constraints

- PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`; Larastan level **8**, **no baseline**, no new `ignoreErrors` entries.
- Pint: `laravel` preset + `declare_strict_types`, `strict_comparison`, `ordered_class_elements`. Every PHP file starts with `declare(strict_types=1);`. Run `composer fix` after editing PHP.
- Livewire components are multi-file (MFC) with no ⚡ in any path; components never call `$this->view()`.
- Every task ends with `composer check` green on SQLite **and** `composer test:pgsql` green on PostgreSQL.
- Never use driver-specific SQL; never call `env()` outside `config/`.
- No hard-coded kit or site name (`skv`) in app-identity values; derive hosts with `App\Tenancy\Hosts`.
- `livewire/flux-pro` and `composer.fluxui.dev` never appear in `composer.json` / `composer.lock`. No new Composer or npm dependencies in M3.
- No permission string literals in app code (`App\Authorization\Permission`); `setPermissionsTeamId()` only in `PermissionsTeam`; `Tenant::bypass()` / scope removal only in the allowed namespaces (`tests/Arch/TenancyRulesTest.php`).
- Code, comments, docs: English. UI copy: `nl` default with `en`; every new `__()` key goes into **both** `lang/nl.json` and `lang/en.json` (same keys, sorted; Dutch uses informal "je").
- Tests are Pest functions (`it(...)`); each file starts with a `/** covers §x.y */` docblock as its first statement (imports after it). A test that expects a database error wraps the failing statement in `DB::transaction(...)`.
- Global test helper names already taken (never redeclare): `viewFiles`, `systemRole`, `badgeRows`, `twoTenantsWithMembers`, `lastQueuedPayload`, `workOneJob`, `deletedAtOf`, `userPolicyCast`, `teamRole`, `systemRoleId`, `roleNamesOf`, `matrixRoutes`, `routeMatrixOutcome`, `kitSourceFiles`, `filesMatching`, `onTenant`, `onApex`, `onAdminHost`, `asSuperAdmin`, `asOrgAdmin`, `asMember`.
- Every new named GET route goes into `matrixRoutes()` or the exclusions of `tests/Feature/Tenancy/RouteHostMatrixTest.php`.
- Commits: Conventional Commits, lower case, imperative; end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never run `herd sites`, `migrate:fresh` or `db:wipe` against the development database; `tinker`, `migrate` and `db:seed` prompt for approval.

## Conventions used in this plan

- All M3 work happens on branch `m3-authentication`, created from `main` in Task 1.
- Test hosts (phpunit): apex `localhost`, admin `admin.localhost`, tenants `{slug}.localhost`, scheme `http`. `Hosts::origin(Hosts::tenant($slug))` is `http://{slug}.localhost`. The passkey relying-party id in tests is `localhost`.
- Use the M2 testing API: `onTenant($organisation)`, `onApex()`, `onAdminHost()`, `asMember()`, `asOrgAdmin()`, `asSuperAdmin()`. Create the users you need **before** switching hosts. `Livewire::test()` skips all middleware, so host and session rules are tested with HTTP requests.
- `Event::fake()` must keep the tenancy events real (`Event::fake([SomeEvent::class])` fakes only the listed events; never call it bare).
- Fortify facts verified for this plan: `Fortify::authenticateUsing()` runs **twice** for users without 2FA (once in `RedirectIfTwoFactorAuthenticatable`, once in `AttemptToAuthenticate`) and has no Timebox; `throttle:<name>` counts every request unless the `Limit` has an `after()` callback; Fortify copies `fortify.passkeys.*` into `passkeys.*` once at registration, and the passkeys package reads `passkeys.*` at request time; `PasskeyLoginController` runs `Passkeys::allowsLogin()` after the ceremony and throws a 422 `InvalidPasskeyException` (`credential` key) when it returns false.

## Scope

**In M3:** spec §4.2 (host-scoped login for password, 2FA and passkeys; registration and verification stay disabled; password reset on the user's own host with a neutral message and throttling; recovery-code copy/download/confirm; passkeys with the apex RP id and per-request origin; failure-only rate limiters; `AuthenticateSession`; email change by signed link), §6.4 handoff, Appendix A P1, P2, R1, E1, DEC-25 M3 items, and the M3 items carried from M2 (bypass arch rule widening, sync-driver `failed()` context, typed overrides).

**Deferred (recorded in DEC-27, Task 10):**
- M4: audit logging of logins, failures, password resets, 2FA changes and handoffs (spatie/laravel-activitylog arrives with the admin UI; M3 dispatches `HandoffConsumed` for it); the "Open" action and slug-rename redirect that issue handoffs.
- M5: the passkeys module flag and its UI (§6.8), Dutch strings for the existing Fortify/Flux auth screens, error pages (429 included), language toggle on auth pages.
- M7: sessions list and "log out other devices" UI (`AuthenticateSession` lands now), scheduling `model:prune` for handoff tokens.

## File map

| Path | Responsibility |
|---|---|
| `app/Tenancy/Auth/HostLoginRule.php` | the single "may sign in here" rule |
| `app/Tenancy/Auth/AuthenticateOnThisHost.php` | Fortify login callback (Timebox, memoised) |
| `app/Tenancy/Auth/UnscopedTwoFactorLoginRequest.php` | 2FA challenge applies the host rule |
| `app/Tenancy/Auth/UnscopedPasskey.php` | passkey user loads with trashed users |
| `app/Tenancy/Hosts.php` | `homeOf(User)` |
| `app/Tenancy/Http/Middleware/InitializeTenancy.php` | per-request passkey origin |
| `app/Providers/FortifyServiceProvider.php` | login callback, reset URL, limiters, views |
| `app/Providers/TenancyServiceProvider.php` | passkey login authorisation |
| `app/Http/Responses/NeutralPasswordResetLinkResponse.php` | same answer for every reset-link request |
| `app/Actions/Fortify/ResetUserPassword.php` | reset only on the user's own host |
| `app/Actions/Settings/{RequestEmailChange,ConfirmEmailChange}.php` | email change (E1) |
| `app/Http/Controllers/Settings/ConfirmEmailChangeController.php` | signed confirmation link |
| `app/Notifications/{ConfirmEmailChange,EmailChangeRequested}.php` | email change mails |
| `app/Actions/Auth/IssueHandoff.php`, `app/Http/Controllers/Auth/HandoffController.php`, `app/Models/HandoffToken.php`, `app/Events/HandoffConsumed.php`, `database/migrations/0001_01_01_000004_create_handoff_tokens_table.php` | cross-host handoff (§6.4) |
| `config/fortify.php`, `.env.example`, `bootstrap/app.php`, `routes/web.php`, `routes/settings.php` | wiring |
| `resources/views/pages/settings/{profile,two-factor/recovery-codes}/*` | email change and recovery-code UX |
| `lang/nl/{auth,passwords}.php`, `lang/en/passwords.php`, `lang/{nl,en}.json` | copy |
| `tests/Support/SoftwareAuthenticator.php` | WebAuthn authenticator for feature tests |
| `tests/Feature/Auth/*`, `tests/Feature/Settings/*`, `tests/Arch/TenancyRulesTest.php`, `tests/Feature/Tenancy/{QueueTenancyTest,RouteHostMatrixTest}.php` | tests |
| `docs/decisions.md`, `CHANGELOG.md`, `.ai/guidelines/skv3.md`, `CLAUDE.md` | docs |

---

### Task 1: One host rule for password and two-factor sign-in

**Files:**
- Create: `app/Tenancy/Auth/HostLoginRule.php`, `app/Tenancy/Auth/AuthenticateOnThisHost.php`
- Modify: `app/Providers/FortifyServiceProvider.php`, `app/Tenancy/Auth/UnscopedTwoFactorLoginRequest.php`
- Test: `tests/Feature/Auth/HostScopedLoginTest.php`

**Interfaces:**
- Consumes: `User::isActive()`, `User::belongsToCurrentHost()`, `User::trashed()`; the `users` auth provider (`unscoped-eloquent`); the M2 testing API.
- Produces: `App\Tenancy\Auth\HostLoginRule::allows(User $user): bool`; `App\Tenancy\Auth\AuthenticateOnThisHost::__invoke(Request $request): ?User`. Tasks 3, 4 and 7 reuse `HostLoginRule`.

- [ ] **Step 1: Create the branch**

```bash
git switch main && git switch -c m3-authentication
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Auth/HostScopedLoginTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Switch to one of the test hosts; 'other tenant' is an organisation the user does not belong to. */
function useLoginHost(string $host, Organisation $own): void
{
    match ($host) {
        'own tenant' => onTenant($own),
        'other tenant' => onTenant(Organisation::factory()->create()),
        'apex' => onApex(),
        default => onAdminHost(),
    };
}

it('signs a member in on their own organisation host only', function (string $host, bool $allowed): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    useLoginHost($host, $organisation);

    $response = $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);

    if ($allowed) {
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($member);
    } else {
        $response->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
    }
})->with([
    'own tenant' => ['own tenant', true],
    'other tenant' => ['other tenant', false],
    'apex' => ['apex', false],
    'admin' => ['admin', false],
]);

it('signs a super admin in on every host', function (string $host): void {
    $organisation = Organisation::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    useLoginHost($host, $organisation);

    $this->post(route('login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($superAdmin);
})->with(['own tenant', 'apex', 'admin']);

it('refuses users that are not active with the wrong-password message', function (string $state): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->for($organisation)->{$state}()->create();
    onTenant($organisation);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
})->with(['pending', 'disabled']);

it('answers a correct password on the wrong host exactly like a wrong password', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onApex();

    $wrongHost = $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $wrongPassword = $this->post(route('login.store'), ['email' => $member->email, 'password' => 'not-the-password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    expect($wrongHost->getStatusCode())->toBe($wrongPassword->getStatusCode());
});

it('never starts a two-factor challenge on a host the user does not belong to', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onApex();

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('login.id');
});

it('refuses a two-factor challenge for a user who may no longer sign in here', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    DB::table('users')->where('id', $member->id)->update(['status' => 'disabled']);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('completes the two-factor challenge on the member own host', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onTenant($organisation);

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($member);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/HostScopedLoginTest.php`
Expected: FAIL — wrong-host and inactive logins are accepted (redirect to dashboard).

- [ ] **Step 4: Add the rule and the login callback**

`app/Tenancy/Auth/HostLoginRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;

/**
 * Who may sign in on the current host (§4.2): an active, non-deleted user who belongs to it.
 * Super admins belong to every host. Password, two-factor and passkey sign-in all use this.
 */
final class HostLoginRule
{
    public function allows(User $user): bool
    {
        return ! $user->trashed() && $user->isActive() && $user->belongsToCurrentHost();
    }
}
```

`app/Tenancy/Auth/AuthenticateOnThisHost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use LogicException;

/**
 * Fortify's login callback (§4.2). An unknown address, a wrong password, an inactive user and a user
 * on a host they do not belong to all fail the same way and take the same time (Timebox), so the
 * response never reveals which one it was. Fortify calls the callback twice for users without 2FA,
 * so the outcome is kept on the request.
 */
final readonly class AuthenticateOnThisHost
{
    private const string RESULT = 'auth.authenticated-on-this-host';

    public function __construct(private HostLoginRule $rule) {}

    public function __invoke(Request $request): ?User
    {
        if ($request->attributes->has(self::RESULT)) {
            $cached = $request->attributes->get(self::RESULT);

            return $cached instanceof User ? $cached : null;
        }

        $user = new Timebox()->call(fn (Timebox $timebox): ?User => $this->attempt($request, $timebox), 200_000);
        $user = $user instanceof User ? $user : null;

        $request->attributes->set(self::RESULT, $user);

        return $user;
    }

    private function attempt(Request $request, Timebox $timebox): ?User
    {
        $provider = Auth::createUserProvider('users') ?? throw new LogicException('The users auth provider is not configured.');
        $user = $provider->retrieveByCredentials(['email' => Str::lower((string) $request->input('email'))]);
        $credentials = ['password' => (string) $request->input('password')];

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            return null;
        }

        $provider->rehashPasswordIfRequired($user, $credentials);

        if (! $this->rule->allows($user)) {
            return null;
        }

        $timebox->returnEarly();

        return $user;
    }
}
```

- [ ] **Step 5: Wire the callback and tidy the provider**

In `app/Providers/FortifyServiceProvider.php`:
1. In `configureActions()` add (imports `App\Models\User`, `App\Tenancy\Auth\AuthenticateOnThisHost`):

```php
        Fortify::authenticateUsing(fn (Request $request): ?User => app(AuthenticateOnThisHost::class)($request));
```

2. DEC-25: add `use Illuminate\Contracts\View\View;` and replace the five `\Illuminate\Contracts\View\View` return types in `configureViews()` with `View`.

- [ ] **Step 6: Apply the rule to the two-factor challenge**

Replace `app/Tenancy/Auth/UnscopedTwoFactorLoginRequest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Fortify's two-factor challenge (§4.1, §4.2): the challenged user is loaded without the tenant scope
 * and must still pass the host rule, so a user who was disabled or moved between the password step
 * and the code step is refused.
 */
final class UnscopedTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function hasChallengedUser(): bool
    {
        return $this->allowedChallengedUser() instanceof User;
    }

    public function challengedUser(): mixed
    {
        return $this->allowedChallengedUser()
            ?? throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse($this));
    }

    /** The failure response is built only in challengedUser(): it flashes an error (HTML) or throws (JSON). */
    private function allowedChallengedUser(): ?User
    {
        if (! $this->session()->has('login.id')) {
            return null;
        }

        $user = app(CurrentTenant::class)->bypass(fn (): ?User => User::query()->find($this->session()->get('login.id')));

        return $user instanceof User && app(HostLoginRule::class)->allows($user) ? $user : null;
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth`
Expected: PASS (the M1/M2 auth tests already log in on the user's own host).

- [ ] **Step 8: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: sign users in only on hosts they belong to

- one HostLoginRule for password and two-factor sign-in
- wrong host, inactive and wrong password fail identically, in constant time
- import the view contract in FortifyServiceProvider (DEC-25)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Rate limiters that count only failed attempts

**Files:**
- Modify: `app/Providers/FortifyServiceProvider.php`, `routes/web.php`, `lang/nl.json`, `lang/en.json`
- Create: `lang/nl/auth.php`
- Test: `tests/Feature/Auth/LoginThrottleTest.php`

**Interfaces:**
- Consumes: the Task 1 login callback.
- Produces: named limiters `login` (5/min per email+IP, 20/min per IP) and `passkeys` (10/min per credential or session + IP), counting failures only; `two-factor` (5/min per challenge) and `password-confirm` (5/min per user), counting every attempt; `password-email` (5/min per email+IP, 20/min per IP) and `handoff` (10/min per IP), counting every request, for Tasks 4 and 7. Route `password.confirm.store` is redefined with `throttle:password-confirm`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/LoginThrottleTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;

it('does not count successful sign-ins', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 6) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

it('locks an address out after five failed attempts', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')?->first('email'))->toStartWith('Too many login attempts');
});

it('locks an IP address out after twenty failed attempts across addresses', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 20) as $attempt) {
        $this->post(route('login.store'), ['email' => "nobody{$attempt}@example.com", 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('limits failed two-factor codes', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong'])
            ->assertRedirect(route('two-factor.login'));
    }

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertTooManyRequests();
});

it('limits password confirmations', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->actingAs($member);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.confirm.store'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    }

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertTooManyRequests();
});

it('has Dutch sign-in messages', function (): void {
    app()->setLocale('nl');

    expect(__('auth.failed'))->toBe('Deze combinatie van e-mailadres en wachtwoord is niet geldig.')
        ->and(__('auth.throttle', ['seconds' => 30]))->toBe('Te veel inlogpogingen. Probeer het over 30 seconden opnieuw.');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/LoginThrottleTest.php`
Expected: FAIL — successful sign-ins are counted (the sixth success is throttled with a 429), there is no IP limit, password confirmation is unthrottled and there is no Dutch `auth.php`.

- [ ] **Step 3: Replace the limiters**

In `app/Providers/FortifyServiceProvider.php` replace `configureRateLimiting()` with the code below and add the two helpers (imports `Closure`, `Illuminate\Http\JsonResponse`, `Symfony\Component\HttpFoundation\Response`):

```php
    /**
     * §4.2: login and passkey limiters count failed attempts only (a response of 400 or more, or
     * validation errors flashed by this request); two-factor, password confirmation, password-reset and
     * handoff limiters count every request (DEC-27).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip())
                    ->after(self::failedAttempt($request))
                    ->response(self::loginThrottled(...)),
                Limit::perMinute(20)->by('login-ip:'.$request->ip())
                    ->after(self::failedAttempt($request))
                    ->response(self::loginThrottled(...)),
            ];
        });

        // Counted before the request (no after()): an after() limit is checked before and counted after the
        // response, so concurrent requests could outrun it. A correct code ends the challenge, so counting
        // successes costs a user nothing in practice (DEC-27).
        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('two-factor:'.$request->session()->get('login.id')));

        RateLimiter::for('password-confirm', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('password-confirm:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('passkeys', function (Request $request): Limit {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)
                ->by('passkeys:'.($credentialId ?: $request->session()->getId()).'|'.$request->ip())
                ->after(self::failedAttempt($request));
        });

        RateLimiter::for('password-email', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-email:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-email-ip:'.$request->ip()),
        ]);

        RateLimiter::for('handoff', fn (Request $request): Limit => Limit::perMinute(10)->by('handoff:'.$request->ip()));
    }

    /** @return Closure(Response): bool */
    private static function failedAttempt(Request $request): Closure
    {
        return fn (Response $response): bool => $response->getStatusCode() >= 400
            || in_array('errors', (array) $request->session()->get('_flash.new', []), true);
    }

    /** @param  array<string, mixed>  $headers */
    private static function loginThrottled(Request $request, array $headers): Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);
        $message = __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]);

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message, 'errors' => [Fortify::username() => [$message]]], 429, $headers);
        }

        return back()
            ->withInput($request->only(Fortify::username()))
            ->withErrors([Fortify::username() => $message])
            ->withHeaders($headers);
    }
```

If Larastan rejects `self::loginThrottled(...)` as a `->response()` argument, pass `fn (Request $request, array $headers): Response => self::loginThrottled($request, $headers)`.

Fortify registers `password.confirm.store` without a limiter, which would leave password confirmation as the one unthrottled password check. In `routes/web.php` redefine it (same method, URI and name, so it replaces Fortify's route, which is registered earlier during boot; import `Laravel\Fortify\Http\Controllers\ConfirmablePasswordController`):

```php
// §4.2: password confirmation is throttled. Same method, URI and name as Fortify's route, so this one replaces it.
Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware(['auth:web', 'throttle:password-confirm'])
    ->name('password.confirm.store');
```

- [ ] **Step 4: Add the Dutch sign-in messages**

`lang/nl/auth.php`:

```php
<?php

declare(strict_types=1);

return [

    'failed' => 'Deze combinatie van e-mailadres en wachtwoord is niet geldig.',
    'password' => 'Het opgegeven wachtwoord is onjuist.',
    'throttle' => 'Te veel inlogpogingen. Probeer het over :seconds seconden opnieuw.',

];
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth`
Expected: PASS.

- [ ] **Step 6: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: count only failed sign-in attempts

- login limited per address and per IP, passkeys per credential, failures only
- two-factor and password confirmation limited per attempt
- friendly throttle message on the login form; Dutch auth messages

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Passkeys on every host (apex RP id, per-request origin, host rule — P1, P2)

**Files:**
- Create: `tests/Support/SoftwareAuthenticator.php`
- Modify: `config/fortify.php`, `.env.example`, `app/Tenancy/Http/Middleware/InitializeTenancy.php`, `app/Providers/TenancyServiceProvider.php`, `app/Tenancy/Auth/UnscopedPasskey.php`, `bootstrap/app.php`, `lang/nl.json`, `lang/en.json`, `tests/Feature/Tenancy/StrictModeTest.php` (only if its passkey test needs the new relation)
- Test: `tests/Feature/Auth/PasskeyTest.php`

**Interfaces:**
- Consumes: `HostLoginRule` (Task 1), `Hosts`, the M2 testing API, Laravel Passkeys (`Passkeys::authorizeLoginUsing`, routes `passkey.registration-options`, `passkey.store`, `passkey.login-options`, `passkey.login`).
- Produces: `passkeys.relying_party_id` = `PASSKEYS_RELYING_PARTY_ID` ?: `TENANCY_APEX_DOMAIN` ?: APP_URL host; `passkeys.allowed_origins` = `[request scheme://host[:port]]` for every web request; WebAuthn ceremony failures answer 422 (`credential`); `Tests\Support\SoftwareAuthenticator` (`attest(array $options, string $origin): array`, `assert(array $options, string $origin): array`).

- [ ] **Step 1: Add the test authenticator**

`tests/Support/SoftwareAuthenticator.php` (the CBOR and Base64 classes come with web-auth/webauthn-lib, already installed through Fortify):

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * A software WebAuthn authenticator (ES256, attestation "none") for feature tests. It answers the
 * browser options of the Laravel Passkeys endpoints, so the package's real validators run, including
 * the origin and relying-party checks.
 */
final class SoftwareAuthenticator
{
    private readonly OpenSSLAsymmetricKey $key;

    private readonly string $credentialId;

    private string $userHandle = '';

    private int $counter = 0;

    public function __construct()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        $this->key = $key instanceof OpenSSLAsymmetricKey ? $key : throw new RuntimeException('Could not create an EC key pair.');
        $this->credentialId = random_bytes(16);
    }

    /**
     * @param  array<string, mixed>  $options  the "options" returned by passkey.registration-options
     * @return array<string, mixed> the "credential" to post to passkey.store
     */
    public function attest(array $options, string $origin): array
    {
        $this->userHandle = self::decode(self::string($options, 'user', 'id'));

        $authenticatorData = hash('sha256', self::string($options, 'rp', 'id'), true)
            .chr(0x45)
            .pack('N', $this->counter)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .(string) $this->publicKey();

        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData));

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($this->clientData('webauthn.create', self::string($options, 'challenge'), $origin)),
                'attestationObject' => self::encode((string) $attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options  the "options" returned by passkey.login-options
     * @return array<string, mixed> the "credential" to post to passkey.login
     */
    public function assert(array $options, string $origin): array
    {
        $this->counter++;

        $authenticatorData = hash('sha256', self::string($options, 'rpId'), true).chr(0x05).pack('N', $this->counter);
        $clientData = $this->clientData('webauthn.get', self::string($options, 'challenge'), $origin);
        openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'authenticatorData' => self::encode($authenticatorData),
                'signature' => self::encode((string) $signature),
                'userHandle' => self::encode($this->userHandle),
            ],
        ];
    }

    private function publicKey(): MapObject
    {
        $details = openssl_pkey_get_details($this->key);
        $curve = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : throw new RuntimeException('Not an EC key.');

        return MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad((string) $curve['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad((string) $curve['y'], 32, "\0", STR_PAD_LEFT)));
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $data */
    private static function string(array $data, string ...$path): string
    {
        $value = $data;

        foreach ($path as $key) {
            $value = is_array($value) && array_key_exists($key, $value)
                ? $value[$key]
                : throw new RuntimeException('Missing passkey option ['.implode('.', $path).'].');
        }

        return is_string($value) ? $value : throw new RuntimeException('Passkey option ['.implode('.', $path).'] is not a string.');
    }

    private static function encode(string $binary): string
    {
        return Base64UrlSafe::encodeUnpadded($binary);
    }

    private static function decode(string $encoded): string
    {
        return Base64UrlSafe::decodeNoPadding(rtrim($encoded, '='));
    }
}
```

This recipe was verified during planning against the real `WebAuthn::attestationValidator()` / `assertionValidator()` (origins `http://acme.localhost` and `https://acme.localhost:8443` with RP id `localhost`). If the browser options encode the challenge differently than the clientData expects (padding), compare with `vendor/laravel/passkeys/src/Support/WebAuthn.php::toBrowserArray()` and decode/re-encode it the same way the package does.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Auth/PasskeyTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SoftwareAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withSession;

/** Register a passkey for the user through the real endpoints on the current host, then sign out. */
function registerPasskeyFor(User $user, SoftwareAuthenticator $authenticator, string $origin): void
{
    actingAs($user);
    withSession(['auth.password_confirmed_at' => time()]);

    /** @var array<string, mixed> $options */
    $options = getJson(route('passkey.registration-options'))->assertOk()->json('options');
    postJson(route('passkey.store'), ['name' => 'Test key', 'credential' => $authenticator->attest($options, $origin)])
        ->assertSuccessful();

    Auth::guard('web')->logout();
}

/** Switch to a test host ('tenant', 'apex' or 'admin') and return its browser origin. */
function passkeyHost(string $host, Organisation $organisation): string
{
    match ($host) {
        'tenant' => onTenant($organisation),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    return Hosts::origin(match ($host) {
        'tenant' => Hosts::tenant($organisation->slug),
        'apex' => Hosts::apex(),
        default => Hosts::admin(),
    });
}

/** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
function signInWithPasskey(SoftwareAuthenticator $authenticator, string $origin): TestResponse
{
    /** @var array<string, mixed> $options */
    $options = getJson(route('passkey.login-options'))->assertOk()->json('options');

    return postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, $origin)]);
}

it('uses the apex as relying party id', function (): void {
    expect(config('passkeys.relying_party_id'))->toBe('localhost');
});

it('allows the origin of the current request for passkey ceremonies (P1)', function (string $host, string $origin): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    passkeyHost($host, $organisation);

    $this->get(route('login'))->assertOk();

    expect(config('passkeys.allowed_origins'))->toBe([$origin]);
})->with([
    'tenant' => ['tenant', 'http://acme.localhost'],
    'apex' => ['apex', 'http://localhost'],
    'admin' => ['admin', 'http://admin.localhost'],
]);

it('registers a passkey on a tenant host and signs the member in with it there', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);

    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    signInWithPasskey($authenticator, 'http://acme.localhost')->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('refuses a passkey sign-in on a host the user does not belong to', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    onApex();

    signInWithPasskey($authenticator, 'http://localhost')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();
});

it('signs a super admin in with a passkey on central and tenant hosts (P2)', function (string $host): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();
    $authenticator = new SoftwareAuthenticator;
    onAdminHost();
    registerPasskeyFor($superAdmin, $authenticator, 'http://admin.localhost');

    signInWithPasskey($authenticator, passkeyHost($host, $organisation))->assertOk();
    $this->assertAuthenticatedAs($superAdmin);
})->with(['tenant', 'apex', 'admin']);

it('refuses a passkey of a user who is no longer active or was deleted', function (string $change): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    match ($change) {
        'disabled' => DB::table('users')->where('id', $member->id)->update(['status' => 'disabled']),
        default => $member->delete(),
    };

    signInWithPasskey($authenticator, 'http://acme.localhost')->assertUnprocessable();
    $this->assertGuest();
})->with(['disabled', 'deleted']);

it('answers a passkey from a foreign origin with 422 instead of a server error', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    signInWithPasskey($authenticator, 'http://evil.localhost')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/PasskeyTest.php`
Expected: FAIL — registration and sign-in on `acme.localhost` throw a WebAuthn origin error (500), the wrong-host sign-in succeeds, and the allowed origin is always `http://localhost`.

- [ ] **Step 4: Relying-party id at the apex**

In `config/fortify.php`, replace the `relying_party_id` line of the `passkeys` block with:

```php
        // §4.2: always the apex, a registrable suffix of the apex, admin.{apex} and every {slug}.{apex},
        // so one passkey works on every host a user may sign in on. Fortify copies this into
        // passkeys.relying_party_id once at boot (config/tenancy.php is not loaded yet here).
        'relying_party_id' => env('PASSKEYS_RELYING_PARTY_ID') ?: (env('TENANCY_APEX_DOMAIN') ?: parse_url((string) config('app.url'), PHP_URL_HOST)),
```

In `.env.example`, after the `TENANCY_*` block, add:

```dotenv
# Passkeys use the apex as relying party id; set this only if it must differ.
PASSKEYS_RELYING_PARTY_ID=
```

- [ ] **Step 5: Allowed origin per request**

In `app/Tenancy/Http/Middleware/InitializeTenancy.php::handle()`, after the tenant is set or forgotten and before `return $next($request);`, add:

```php
        // P1: passkey ceremonies are checked against the origin of this request; the RP id stays the apex.
        // Set here (not in TenantResolved/TenantForgotten listeners) because Tenant::run() inside a request
        // switches tenants without changing the browser origin.
        config(['passkeys.allowed_origins' => [$request->getSchemeAndHttpHost()]]);
```

- [ ] **Step 6: The host rule for passkey sign-in, and deleted users**

In `app/Providers/TenancyServiceProvider.php::boot()`, after `Passkeys::usePasskeyModel(UnscopedPasskey::class);`, add (imports `App\Models\User`, `App\Tenancy\Auth\HostLoginRule`, `Illuminate\Http\Request`):

```php
        // §4.2: passkey sign-in follows the same host rule as password sign-in (P2).
        Passkeys::authorizeLoginUsing(fn (Request $request, mixed $user): bool => $user instanceof User
            && app(HostLoginRule::class)->allows($user));
```

In `app/Tenancy/Auth/UnscopedPasskey.php`, change `user()` so a soft-deleted owner still loads (the package dispatches `PasskeyVerified($passkey->user)` before the authorisation check, and a null user is a TypeError) and the host rule then refuses it:

```php
    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return parent::user()->withoutGlobalScopes([TenantScope::class, SoftDeletingScope::class]);
    }
```

(import `Illuminate\Database\Eloquent\SoftDeletingScope`; update the class docblock: "loads its user without the tenant scope and including deleted users; HostLoginRule refuses deleted and inactive users").

- [ ] **Step 7: Ceremony failures answer 422**

In `bootstrap/app.php`, inside `withExceptions`, add (imports `Webauthn\Exception\WebauthnException`, `Illuminate\Http\JsonResponse`):

```php
        // A failed WebAuthn ceremony (origin, challenge, signature, counter) is a client error, not a 500.
        $exceptions->render(fn (WebauthnException $exception): JsonResponse => new JsonResponse([
            'message' => __('This passkey could not be verified.'),
            'errors' => ['credential' => [__('This passkey could not be verified.')]],
        ], 422));
```

Confirm the base class name in `vendor/web-auth/webauthn-lib/src/Exception/` first; if the hierarchy uses a different root (for example `Webauthn\Exception\WebauthnException` vs per-ceremony exceptions), render on the common parent the package throws.

Add to both lang files: `"This passkey could not be verified."` (nl: `"Deze passkey kon niet worden geverifieerd."`).

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth/PasskeyTest.php tests/Feature/Tenancy/StrictModeTest.php`
Expected: PASS.

- [ ] **Step 9: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: make passkeys work on every host

- relying party id at the apex, allowed origin from the request (P1)
- passkey sign-in follows the host rule; super admins everywhere (P2)
- deleted users and failed ceremonies answer 422 instead of 500
- software authenticator for feature tests

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Password reset on the user's own host (R1)

**Files:**
- Create: `app/Http/Responses/NeutralPasswordResetLinkResponse.php`, `app/Http/Controllers/Auth/SendPasswordResetLinkController.php`, `lang/nl/passwords.php`, `lang/en/passwords.php`
- Modify: `app/Tenancy/Hosts.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/ResetUserPassword.php`, `routes/web.php`, `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Auth/PasswordResetHostTest.php`

**Interfaces:**
- Consumes: limiter `password-email` (Task 2), `Hosts`, `tenant_url()`.
- Produces: `App\Tenancy\Hosts::homeOf(User $user): string` (admin host for super admins, the organisation's host otherwise) — used by Tasks 6 and 7; reset links always point at `Hosts::homeOf($user)`; every reset-link request answers `passwords.sent`; `password.email` is throttled; a reset token works only on the user's own host.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/PasswordResetHostTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends a member the reset link for their own host, wherever it was requested (R1)', function (string $host): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    match ($host) {
        'own tenant' => onTenant($organisation),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    $this->post(route('password.email'), ['email' => $member->email])->assertSessionHasNoErrors();

    Notification::assertSentTo($member, ResetPassword::class, fn (ResetPassword $notification): bool => str_starts_with(
        (string) $notification->toMail($member)->actionUrl,
        'http://acme.localhost/reset-password/',
    ));
})->with(['own tenant', 'apex', 'admin']);

it('sends a super admin the reset link for the admin host', function (): void {
    Notification::fake();
    $superAdmin = User::factory()->superAdmin()->create();
    onApex();

    $this->post(route('password.email'), ['email' => $superAdmin->email]);

    Notification::assertSentTo($superAdmin, ResetPassword::class, fn (ResetPassword $notification): bool => str_starts_with(
        (string) $notification->toMail($superAdmin)->actionUrl,
        'http://admin.localhost/reset-password/',
    ));
});

it('answers every reset-link request the same way', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $responses = [
        'known' => $this->post(route('password.email'), ['email' => $member->email]),
        'requested again' => $this->post(route('password.email'), ['email' => $member->email]),
        'unknown' => $this->post(route('password.email'), ['email' => 'nobody@example.com']),
    ];

    foreach ($responses as $response) {
        $response->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
    }
});

it('throttles reset-link requests', function (): void {
    Notification::fake();
    onApex();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => 'someone@example.com']);
    }

    $this->post(route('password.email'), ['email' => 'someone@example.com'])->assertTooManyRequests();
});

it('refuses a reset token on a host the user does not belong to', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $token = Password::broker()->createToken($member);
    onApex();

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $member->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', (string) $member->fresh()?->password))->toBeTrue();
});

it('has a neutral Dutch reset message', function (): void {
    app()->setLocale('nl');

    expect(__('passwords.sent'))->toBe('Als dit e-mailadres bij ons bekend is, hebben we je een link gestuurd om je wachtwoord opnieuw in te stellen.');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/PasswordResetHostTest.php`
Expected: FAIL — links point at the requesting host, unknown and repeated requests return errors, `password.email` is not throttled, the reset works on the apex.

- [ ] **Step 3: The home host of a user**

Add to `app/Tenancy/Hosts.php` (imports `App\Models\User`, `LogicException`):

```php
    /** The host a user signs in on: the admin host for super admins, their organisation's host otherwise. */
    public static function homeOf(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return self::admin();
        }

        $organisation = $user->organisation ?? throw new LogicException("User [{$user->id}] has no organisation.");

        return self::tenant($organisation->slug);
    }
```

- [ ] **Step 4: Reset links, neutral answer, host-bound reset**

In `app/Providers/FortifyServiceProvider.php`:
- `configureActions()` (imports `App\Tenancy\Hosts`, `Illuminate\Auth\Notifications\ResetPassword`):

```php
        // R1: the reset link points at the user's own host, whichever host the request came from.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => Hosts::url(
            Hosts::homeOf($user),
            route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], absolute: false),
        ));
```

- `register()` (imports `Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse`, `App\Http\Responses\NeutralPasswordResetLinkResponse`):

```php
        // R1: unknown, throttled and successful reset-link requests all get the same answer.
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
```

`app/Http/Responses/NeutralPasswordResetLinkResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Answers a failed reset-link request exactly like a successful one, so addresses cannot be probed (R1). */
final class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        $message = __(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message])
            : back()->with('status', $message);
    }
}
```

(Fortify resolves the contract with a `status` argument; the container ignores it because the constructor takes none.)

In `app/Actions/Fortify/ResetUserPassword.php::reset()`, add as the first statements (import `Illuminate\Validation\ValidationException`):

```php
        // R1: a reset token works only on the user's own host.
        if (! $user->belongsToCurrentHost()) {
            throw ValidationException::withMessages(['email' => [__('passwords.token')]]);
        }
```

- [ ] **Step 5: Throttled, neutral and constant-time reset-link requests**

Fortify registers `password.email` without a limiter, and its controller sends the mail synchronously (a known address takes measurably longer: token hashing plus SMTP). Replace it with a controller that answers at once and runs the broker after the response.

`app/Http/Controllers/Auth/SendPasswordResetLinkController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

use function Illuminate\Support\defer;

/** R1: the link is sent after the response, so neither the answer nor its timing reveals whether the address exists. */
final class SendPasswordResetLinkController extends Controller
{
    public function __invoke(SendPasswordResetLinkRequest $request): Responsable
    {
        $email = Str::lower((string) $request->input(Fortify::email()));

        defer(fn (): string => Password::broker(config('fortify.passwords'))->sendResetLink([Fortify::email() => $email]));

        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);
    }
}
```

(`defer()` callbacks run in the terminating phase, which the test client also runs, so `Notification::fake()` still sees the mail. If Larastan objects to the broker's return type or `config()` argument, cast with `(string)` / adjust the closure's return type.)

In `routes/web.php` (import the controller):

```php
// §4.2 / R1: throttled, neutral and constant-time. Same method, URI and name as Fortify's route, so this one replaces it.
Route::post('forgot-password', SendPasswordResetLinkController::class)
    ->middleware(['guest:web', 'throttle:password-email'])
    ->name('password.email');
```

`NeutralPasswordResetLinkResponse` (Step 4) stays as defence in depth for any other caller of Fortify's controller.

- [ ] **Step 6: Reset messages and mail copy**

`lang/nl/passwords.php`:

```php
<?php

declare(strict_types=1);

return [

    'reset' => 'Je wachtwoord is opnieuw ingesteld.',
    'sent' => 'Als dit e-mailadres bij ons bekend is, hebben we je een link gestuurd om je wachtwoord opnieuw in te stellen.',
    'throttled' => 'Wacht even voordat je het opnieuw probeert.',
    'token' => 'Deze link om je wachtwoord opnieuw in te stellen is ongeldig.',
    'user' => 'Als dit e-mailadres bij ons bekend is, hebben we je een link gestuurd om je wachtwoord opnieuw in te stellen.',

];
```

`lang/en/passwords.php`:

```php
<?php

declare(strict_types=1);

return [

    'reset' => 'Your password has been reset.',
    'sent' => 'If that email address is known to us, we have sent you a link to reset your password.',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This password reset link is invalid.',
    'user' => 'If that email address is known to us, we have sent you a link to reset your password.',

];
```

Add the reset mail lines to both JSON files (nl values shown; en = key):

| Key | nl |
|---|---|
| `If you did not request a password reset, no further action is required.` | `Heb je dit niet aangevraagd? Dan hoef je niets te doen.` |
| `Reset Password` | `Wachtwoord opnieuw instellen` |
| `Reset Password Notification` | `Wachtwoord opnieuw instellen` |
| `This password reset link will expire in :count minutes.` | `Deze link verloopt over :count minuten.` |
| `You are receiving this email because we received a password reset request for your account.` | `Je ontvangt deze e-mail omdat we een verzoek hebben gekregen om het wachtwoord van je account opnieuw in te stellen.` |

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth`
Expected: PASS (the existing `PasswordResetTest` still passes: it resets on the member's own host).

- [ ] **Step 8: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green. `InviteOnlyTest`'s DELETE-route table is unaffected (the redefined route is POST).

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "fix: send password reset links for the user's own host (R1)

- links built for Hosts::homeOf(), neutral answer in constant time (broker runs after the response)
- password.email throttled; reset tokens work only on the own host
- Dutch reset messages and mail copy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `AuthenticateSession` on every web request

**Files:**
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Auth/AuthenticateSessionTest.php`

**Interfaces:**
- Consumes: the web group wiring from M2.
- Produces: `Illuminate\Session\Middleware\AuthenticateSession` appended to the `web` group after `VerifyTenantMembership` (so it also runs on Livewire updates); M7's sessions page builds on it.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/AuthenticateSessionTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

it('signs a session out once the password changed elsewhere', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);
    $this->get(route('dashboard'))->assertOk();

    DB::table('users')->where('id', $member->id)->update(['password' => Hash::make('changed-elsewhere')]);
    // Another device changed the password: drop the user model the test's guard still caches.
    Auth::guard('web')->forgetUser();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('keeps the current device signed in after logging out other devices', function (): void {
    Route::middleware(['web', 'auth'])->post('/_logout-other-devices', function (): string {
        Auth::logoutOtherDevices('password');

        return 'ok';
    });
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);

    $this->post('/_logout-other-devices')->assertOk();

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($member);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/AuthenticateSessionTest.php`
Expected: the first test FAILS (the session survives the password change); the second may pass or fail — it guards the current-device case once the middleware is on.

- [ ] **Step 3: Add the middleware**

In `bootstrap/app.php`, extend the web append list (import `Illuminate\Session\Middleware\AuthenticateSession`):

```php
        $middleware->web(append: [VerifyTenantMembership::class, AuthenticateSession::class], prepend: [InitializeTenancy::class]);
```

(Keep the argument order Rector chose; only add the class after `VerifyTenantMembership`.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature`
Expected: PASS.

- [ ] **Step 5: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: end sessions whose password changed elsewhere

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Email change by signed link (E1) and no more `email_verified_at`

**Files:**
- Create: `app/Actions/Settings/RequestEmailChange.php`, `app/Actions/Settings/ConfirmEmailChange.php`, `app/Http/Controllers/Settings/ConfirmEmailChangeController.php`, `app/Notifications/ConfirmEmailChange.php`, `app/Notifications/EmailChangeRequested.php`
- Modify: `resources/views/pages/settings/profile/profile.php`, `resources/views/pages/settings/profile/profile.blade.php`, `routes/settings.php`, `database/migrations/0001_01_01_000000_create_users_table.php`, `app/Models/User.php`, `database/factories/UserFactory.php`, `tests/Pest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/Tenancy/RouteHostMatrixTest.php`, `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Settings/EmailChangeTest.php`

**Interfaces:**
- Consumes: `Hosts::homeOf()` (Task 4), `App\Concerns\ProfileValidationRules::emailRules(?int $userId)`.
- Produces: `App\Actions\Settings\RequestEmailChange::handle(User $user, string $email): void` (sends a signed link to the new address and a notice to the old one; email unchanged); `RequestEmailChange::cacheKey(User $user): string`; `App\Actions\Settings\ConfirmEmailChange::handle(User $user, string $email, string $nonce): void` (only the newest link works, once); route `email-change.confirm` (`GET settings/email/confirm/{user}`, `auth` + `signed:relative`); notifications `ConfirmEmailChange` (`public string $url`) and `EmailChangeRequested` (`public string $newEmail`); global test helper `confirmationLinkFor(string $address): string`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Pest.php` (imports `App\Notifications\ConfirmEmailChange`, `Illuminate\Notifications\AnonymousNotifiable`, `Illuminate\Support\Facades\Notification`):

```php
/** The email-change confirmation link sent to an address (requires Notification::fake()). */
function confirmationLinkFor(string $address): string
{
    $link = null;

    Notification::assertSentOnDemand(
        ConfirmEmailChange::class,
        function (ConfirmEmailChange $notification, array $channels, AnonymousNotifiable $notifiable) use ($address, &$link): bool {
            if (($notifiable->routes['mail'] ?? null) !== $address) {
                return false;
            }

            $link = $notification->url;

            return true;
        },
    );

    return is_string($link) ? $link : throw new RuntimeException("No confirmation link was sent to [{$address}].");
}
```

`tests/Feature/Settings/EmailChangeTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Actions\Settings\RequestEmailChange;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EmailChangeRequested;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** @return array{0: Organisation, 1: User} */
function memberChangingEmail(): array
{
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    onTenant($organisation);

    return [$organisation, $member];
}

it('mails a confirmation link to the new address and a notice to the old one without changing anything yet (E1)', function (): void {
    [, $member] = memberChangingEmail();

    app(RequestEmailChange::class)->handle($member, 'New@Example.com');

    expect($member->fresh()?->email)->toBe('old@example.com')
        ->and(confirmationLinkFor('new@example.com'))->toStartWith('http://acme.localhost/settings/email/confirm/');
    Notification::assertSentOnDemand(
        EmailChangeRequested::class,
        fn (EmailChangeRequested $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->newEmail === 'new@example.com'
            && ($notifiable->routes['mail'] ?? null) === 'old@example.com',
    );
});

it('changes the address when the signed-in user follows the link', function (): void {
    [, $member] = memberChangingEmail();
    DB::table('password_reset_tokens')->insert(['email' => 'old@example.com', 'token' => 'x', 'created_at' => now()]);
    app(RequestEmailChange::class)->handle($member, 'new@example.com');

    $this->actingAs($member)->get(confirmationLinkFor('new@example.com'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', __('Your email address has been changed.'));

    expect($member->fresh()?->email)->toBe('new@example.com')
        ->and(DB::table('password_reset_tokens')->where('email', 'old@example.com')->exists())->toBeFalse();
});

it('sends a guest to the login page first', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');

    $this->get(confirmationLinkFor('new@example.com'))->assertRedirect(route('login'));

    expect($member->fresh()?->email)->toBe('old@example.com');
});

it('refuses a tampered or expired link', function (string $case): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    $link = confirmationLinkFor('new@example.com');

    if ($case === 'tampered') {
        $link = str_replace('new%40example.com', 'evil%40example.com', $link);
    } else {
        $this->travel(61)->minutes();
    }

    $this->actingAs($member)->get($link)->assertForbidden();
    expect($member->fresh()?->email)->toBe('old@example.com');
})->with(['tampered', 'expired']);

it('refuses a link opened by another user', function (): void {
    [$organisation, $member] = memberChangingEmail();
    $colleague = User::factory()->for($organisation)->create();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');

    $this->actingAs($colleague)->get(confirmationLinkFor('new@example.com'))->assertForbidden();
});

it('uses a link only once', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    $link = confirmationLinkFor('new@example.com');
    $this->actingAs($member)->get($link);
    $member->refresh();

    $this->actingAs($member)->get($link)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', __('This confirmation link is no longer valid.'));
});

it('accepts only the newest link', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'first@example.com');
    app(RequestEmailChange::class)->handle($member, 'second@example.com');

    $this->actingAs($member)->get(confirmationLinkFor('first@example.com'))
        ->assertSessionHas('status', __('This confirmation link is no longer valid.'));

    expect($member->fresh()?->email)->toBe('old@example.com');
});

it('refuses an address that was taken in the meantime', function (): void {
    [$organisation, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    User::factory()->for($organisation)->create(['email' => 'new@example.com']);

    $this->actingAs($member)->get(confirmationLinkFor('new@example.com'))->assertRedirect(route('profile.edit'));

    expect($member->fresh()?->email)->toBe('old@example.com');
});
```

In `tests/Feature/Settings/ProfileUpdateTest.php`, three tests assume the email changes immediately. Rewrite them (add `use Illuminate\Support\Facades\Notification;`):

```php
test('profile information can be updated', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Test User')
        ->and($user->email)->not->toBe('test@example.com')
        ->and(confirmationLinkFor('test@example.com'))->toContain('/settings/email/confirm/');
});

test('email address is stored lower-cased and works for login in any casing', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'Foo@Example.COM')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();
    $this->get(confirmationLinkFor('foo@example.com'))->assertRedirect(route('profile.edit'));
    $this->post(route('logout'));

    expect($user->refresh()->email)->toBe('foo@example.com');
    $this->post(route('login.store'), ['email' => 'FOO@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

test('email address of a deleted user can be reused', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'former@example.com'])->delete();
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'former@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();
    $this->get(confirmationLinkFor('former@example.com'));

    expect($user->refresh()->email)->toBe('former@example.com');
});
```

Keep the existing names if they differ slightly; the other ProfileUpdateTest tests (page renders, uniqueness ignoring case) stay as they are.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Settings`
Expected: FAIL — `Class "App\Actions\Settings\RequestEmailChange" not found`, and the profile still changes the email immediately.

- [ ] **Step 3: Notifications**

`app/Notifications/ConfirmEmailChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Settings\RequestEmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the new address; the change applies only after this link is followed (E1). */
final class ConfirmEmailChange extends Notification implements ShouldQueue
{
    use Queueable;

    /** The link is built during the request, on the user's own host (§6.2). */
    public function __construct(public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('Confirm your new email address'))
            ->line(__('Confirm that you want to use this email address for your account.'))
            ->action(__('Confirm email address'), $this->url)
            ->line(__('This link expires in :count minutes.', ['count' => RequestEmailChange::EXPIRES_IN_MINUTES]))
            ->line(__('If you did not ask for this, you can ignore this email.'));
    }
}
```

`app/Notifications/EmailChangeRequested.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the current address when someone asks to change it (E1). */
final class EmailChangeRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('Your email address is about to change'))
            ->line(__('Someone asked to change the email address of your account to :email.', ['email' => $this->newEmail]))
            ->line(__('Nothing changes until the link sent to that address is followed.'))
            ->line(__('If this was not you, change your password and contact your administrator.'));
    }
}
```

- [ ] **Step 4: The two actions**

`app/Actions/Settings/RequestEmailChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts an email change (E1): the new address gets a signed link on the user's own host, the
 * current address gets a notice. Nothing changes until ConfirmEmailChange runs. The link carries a
 * nonce whose hash is cached per user, so only the newest link works, and only once.
 */
final class RequestEmailChange
{
    use ProfileValidationRules;

    public const int EXPIRES_IN_MINUTES = 60;

    public static function cacheKey(User $user): string
    {
        return 'email-change.'.$user->id;
    }

    /** @throws ValidationException */
    public function handle(User $user, string $email): void
    {
        $email = Str::lower(trim($email));

        if ($email === $user->email) {
            return;
        }

        Validator::make(['email' => $email], ['email' => $this->emailRules($user->id)])->validate();

        $nonce = Str::random(40);
        Cache::put(self::cacheKey($user), hash('sha256', $nonce.'|'.$email), now()->addMinutes(self::EXPIRES_IN_MINUTES));

        $url = Hosts::url(Hosts::homeOf($user), URL::temporarySignedRoute(
            'email-change.confirm',
            now()->addMinutes(self::EXPIRES_IN_MINUTES),
            ['user' => $user->id, 'email' => $email, 'nonce' => $nonce],
            absolute: false,
        ));

        Notification::route('mail', $email)->notify(new ConfirmEmailChange($url));
        // The notice goes to the address as it is now, even if the worker runs after the change was confirmed.
        Notification::route('mail', $user->email)->notify(new EmailChangeRequested($email));
    }
}
```

`app/Actions/Settings/ConfirmEmailChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Applies a confirmed email change (E1); only the newest link works, and only once (the nonce is pulled). */
final class ConfirmEmailChange
{
    use ProfileValidationRules;

    /** @throws ValidationException */
    public function handle(User $user, string $email, string $nonce): void
    {
        $email = Str::lower($email);
        $expected = Cache::pull(RequestEmailChange::cacheKey($user));

        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $nonce.'|'.$email))) {
            throw ValidationException::withMessages(['email' => [__('This confirmation link is no longer valid.')]]);
        }

        Validator::make(['email' => $email], ['email' => $this->emailRules($user->id)])->validate();

        DB::transaction(function () use ($user, $email): void {
            $previous = $user->email;

            $user->forceFill(['email' => $email])->save();

            // Reset links issued for the old address must not work any more.
            DB::table('password_reset_tokens')->where('email', $previous)->delete();
        });
    }
}
```

- [ ] **Step 5: The confirmation route and controller**

`app/Http/Controllers/Settings/ConfirmEmailChangeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class ConfirmEmailChangeController extends Controller
{
    public function __invoke(Request $request, ConfirmEmailChange $confirmEmailChange, string $user): RedirectResponse
    {
        $current = $request->user();

        abort_unless($current instanceof User && (string) $current->id === $user, 403);

        try {
            $confirmEmailChange->handle($current, (string) $request->query('email'), (string) $request->query('nonce'));
        } catch (ValidationException $exception) {
            return to_route('profile.edit')->with('status', (string) Arr::first(Arr::flatten($exception->errors())));
        }

        return to_route('profile.edit')->with('status', __('Your email address has been changed.'));
    }
}
```

(If the existing base controller is not `App\Http\Controllers\Controller` or the preset forbids `extends`, follow the existing controller convention.)

In `routes/settings.php`, inside the `auth` group (import the controller):

```php
    Route::get('settings/email/confirm/{user}', ConfirmEmailChangeController::class)
        ->middleware('signed:relative')
        ->whereNumber('user')
        ->name('email-change.confirm');
```

(No route-model binding on `{user}`: on central hosts it would query a tenant model before any code could bypass the scope — DEC-26 M4 note.)

Add `'email-change.confirm'` to the exclusions in `tests/Feature/Tenancy/RouteHostMatrixTest.php` (it needs a signature and a parameter).

- [ ] **Step 6: The profile form requests instead of applying**

In `resources/views/pages/settings/profile/profile.php` replace `updateProfileInformation()` with (imports `App\Actions\Settings\RequestEmailChange`):

```php
    /**
     * Save the name; a new email address only applies after it is confirmed through the mailed link (E1).
     */
    public function updateProfileInformation(RequestEmailChange $requestEmailChange): void
    {
        $user = $this->user();

        $this->email = Str::lower($this->email);

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill(['name' => $validated['name']])->save();

        $email = (string) $validated['email'];

        if ($email !== $user->email) {
            $requestEmailChange->handle($user, $email);
            $this->email = $user->email;

            Flux::toast(variant: 'success', text: __('We sent a confirmation link to :email.', ['email' => $email]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }
```

In `profile.blade.php`, directly inside `<x-pages::settings.layout …>` above the form, add:

```blade
        @if (session('status'))
            <flux:callout icon="information-circle" class="mt-6" :heading="session('status')" />
        @endif
```

and under the email input:

```blade
                <flux:text class="mt-2 text-sm">{{ __('A new address is used after you confirm it through the link we email to it.') }}</flux:text>
```

- [ ] **Step 7: Remove the unused `email_verified_at` (DEC-25)**

Verification is off by design (invite-only), and nothing reads the column. Remove:
- the `$table->timestamp('email_verified_at')->nullable();` line in `database/migrations/0001_01_01_000000_create_users_table.php`;
- the `email_verified_at` cast and the `@property … $email_verified_at` line in `app/Models/User.php`;
- the `'email_verified_at' => now(),` key and the `unverified()` state in `database/factories/UserFactory.php`.

Run `grep -rn "email_verified_at\|unverified()" app database tests resources routes config` afterwards; expected: no matches.

- [ ] **Step 8: Copy**

Add to both lang files (nl shown; en = key):

| Key | nl |
|---|---|
| `A new address is used after you confirm it through the link we email to it.` | `Een nieuw adres wordt pas gebruikt nadat je het bevestigt via de link die we ernaartoe mailen.` |
| `Confirm email address` | `E-mailadres bevestigen` |
| `Confirm that you want to use this email address for your account.` | `Bevestig dat je dit e-mailadres voor je account wilt gebruiken.` |
| `Confirm your new email address` | `Bevestig je nieuwe e-mailadres` |
| `If this was not you, change your password and contact your administrator.` | `Was jij dit niet? Wijzig dan je wachtwoord en neem contact op met je beheerder.` |
| `If you did not ask for this, you can ignore this email.` | `Heb je dit niet gevraagd? Dan kun je deze e-mail negeren.` |
| `Nothing changes until the link sent to that address is followed.` | `Er verandert niets totdat de link naar dat adres wordt gevolgd.` |
| `Someone asked to change the email address of your account to :email.` | `Iemand heeft gevraagd het e-mailadres van je account te wijzigen in :email.` |
| `This confirmation link is no longer valid.` | `Deze bevestigingslink is niet meer geldig.` |
| `This link expires in :count minutes.` | `Deze link verloopt over :count minuten.` |
| `We sent a confirmation link to :email.` | `We hebben een bevestigingslink gestuurd naar :email.` |
| `Your email address has been changed.` | `Je e-mailadres is gewijzigd.` |
| `Your email address is about to change` | `Je e-mailadres gaat veranderen` |

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Settings tests/Feature/Tenancy/RouteHostMatrixTest.php`
Expected: PASS.

- [ ] **Step 10: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat: confirm email changes through a signed link (E1)

- the newest link works once, on the user's own host; the old address gets a notice
- the profile form no longer applies the address directly
- remove the unused email_verified_at column and factory state (DEC-25)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Cross-host handoff (§6.4)

**Files:**
- Create: `database/migrations/0001_01_01_000004_create_handoff_tokens_table.php`, `app/Models/HandoffToken.php`, `app/Actions/Auth/IssueHandoff.php`, `app/Http/Controllers/Auth/HandoffController.php`, `app/Events/HandoffConsumed.php`
- Modify: `routes/web.php`, `tests/Feature/Tenancy/RouteHostMatrixTest.php`, `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Auth/HandoffTest.php`

**Interfaces:**
- Consumes: `HostLoginRule` (Task 1), `Hosts::homeOf()` (Task 4), limiter `handoff` (Task 2).
- Produces: `App\Actions\Auth\IssueHandoff::handle(User $user, string $targetHost, string $path = '/dashboard'): string` (absolute URL; throws `AuthorizationException` when the user may not enter the host, `InvalidArgumentException` for a non-relative path); route `auth.handoff` (`GET auth/handoff/{token}`); event `App\Events\HandoffConsumed(User $user, string $host)` for M4's audit log; `App\Models\HandoffToken` (mass-prunable after a day; M7 schedules `model:prune`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/HandoffTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.4 */

use App\Actions\Auth\IssueHandoff;
use App\Events\HandoffConsumed;
use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('signs a super admin in on another host with a single-use link', function (): void {
    Event::fake([HandoffConsumed::class]);
    Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();

    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'), '/settings/profile');

    expect($url)->toStartWith('http://acme.localhost/auth/handoff/');
    $this->get($url)
        ->assertRedirect('http://acme.localhost/settings/profile')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertAuthenticatedAs($superAdmin);
    Event::assertDispatched(HandoffConsumed::class, fn (HandoffConsumed $event): bool => $event->user->is($superAdmin)
        && $event->host === 'acme.localhost');
});

it('refuses a link that was used, expired, opened on another host or whose user was disabled', function (string $case): void {
    Organisation::factory()->create(['slug' => 'acme']);
    Organisation::factory()->create(['slug' => 'beta']);
    $superAdmin = User::factory()->superAdmin()->create();
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));

    if ($case === 'used') {
        $this->get($url);
        auth()->logout();
    } elseif ($case === 'expired') {
        $this->travel(61)->seconds();
    } elseif ($case === 'other host') {
        $url = str_replace('acme.localhost', 'beta.localhost', $url);
    } else {
        DB::table('users')->where('id', $superAdmin->id)->update(['status' => 'disabled']);
    }

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('This sign-in link is invalid or has expired.'));
    $this->assertGuest();
})->with(['used', 'expired', 'other host', 'disabled']);

it('lets a member hand off to their own host only', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    Organisation::factory()->create(['slug' => 'beta']);
    $member = User::factory()->for($organisation)->create();

    expect(app(IssueHandoff::class)->handle($member, Hosts::tenant('acme')))->toStartWith('http://acme.localhost/');
    expect(fn () => app(IssueHandoff::class)->handle($member, Hosts::tenant('beta')))->toThrow(AuthorizationException::class);
    expect(fn () => app(IssueHandoff::class)->handle($member, Hosts::admin()))->toThrow(AuthorizationException::class);
});

it('only hands off to relative paths', function (string $path): void {
    $superAdmin = User::factory()->superAdmin()->create();

    expect(fn () => app(IssueHandoff::class)->handle($superAdmin, Hosts::admin(), $path))
        ->toThrow(InvalidArgumentException::class);
})->with(['https://evil.test/', '//evil.test/', 'dashboard', '/\\evil.test']);

it('signs out a different user and starts a clean session', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));
    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get($url);

    $this->assertAuthenticatedAs($superAdmin);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('prunes tokens that expired more than a day ago', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    app(IssueHandoff::class)->handle($superAdmin, Hosts::admin());
    DB::table('handoff_tokens')->insert([
        'user_id' => $superAdmin->id, 'token_hash' => hash('sha256', 'old'), 'target_host' => Hosts::admin(),
        'path' => '/dashboard', 'expires_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);

    Artisan::call('model:prune', ['--model' => [HandoffToken::class]]);

    expect(HandoffToken::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/HandoffTest.php`
Expected: FAIL — `Class "App\Actions\Auth\IssueHandoff" not found`.

- [ ] **Step 3: The table and the model**

`database/migrations/0001_01_01_000004_create_handoff_tokens_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handoff_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('target_host');
            $table->string('path');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoff_tokens');
    }
};
```

`app/Models/HandoffToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single-use, host-bound sign-in link (§6.4). Central (no organisation); only the hash is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $target_host
 * @property string $path
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'target_host', 'path', 'expires_at'])]
final class HandoffToken extends Model
{
    use MassPrunable;

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subDay());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 4: Issuing a handoff**

`app/Actions/Auth/IssueHandoff.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues a single-use sign-in link for another host (§6.4): valid 60 seconds, bound to the user and the
 * target host, only for relative paths. Super admins may enter any host; members only their own
 * organisation's host (used after a slug rename). Session cookies stay host-only.
 */
final class IssueHandoff
{
    public const int TTL_SECONDS = 60;

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(User $user, string $targetHost, string $path = '/dashboard'): string
    {
        $targetHost = Str::lower($targetHost);

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new InvalidArgumentException('A handoff path must be a relative path on the target host.');
        }

        if (! $this->mayEnter($user, $targetHost)) {
            throw new AuthorizationException('This user may not sign in on that host.');
        }

        $token = Str::random(64);

        HandoffToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'target_host' => $targetHost,
            'path' => $path,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return Hosts::url($targetHost, '/auth/handoff/'.$token);
    }

    private function mayEnter(User $user, string $host): bool
    {
        if ($user->trashed() || ! $user->isActive()) {
            return false;
        }

        if (! $user->isSuperAdmin()) {
            return $host === Hosts::homeOf($user);
        }

        return in_array($host, [Hosts::apex(), Hosts::admin()], true) || $this->isOrganisationHost($host);
    }

    private function isOrganisationHost(string $host): bool
    {
        $suffix = '.'.Hosts::apex();

        if (! str_ends_with($host, $suffix)) {
            return false;
        }

        return Organisation::query()->where('slug', substr($host, 0, -strlen($suffix)))->exists();
    }
}
```

- [ ] **Step 5: Consuming a handoff**

`app/Events/HandoffConsumed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;

/** A handoff link was used; M4's audit log records it (§6.4). */
final readonly class HandoffConsumed
{
    public function __construct(public User $user, public string $host) {}
}
```

`app/Http/Controllers/Auth/HandoffController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Events\HandoffConsumed;
use App\Http\Controllers\Controller;
use App\Models\HandoffToken;
use App\Models\User;
use App\Tenancy\Auth\HostLoginRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Consumes a handoff link on its target host and signs the user in there (§6.4). */
final class HandoffController extends Controller
{
    public function __invoke(Request $request, HostLoginRule $rule, string $token): RedirectResponse
    {
        $handoff = HandoffToken::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $handoff instanceof HandoffToken || ! $this->claim($handoff, $request)) {
            return $this->refuse();
        }

        $user = Auth::createUserProvider('users')?->retrieveById($handoff->user_id);

        if (! $user instanceof User || ! $rule->allows($user)) {
            return $this->refuse();
        }

        // A handoff always starts a clean session: nothing from an earlier user (password confirmation,
        // intended URL, later impersonation) carries over.
        if (Auth::check()) {
            Auth::logout();
        }

        $request->session()->invalidate();
        Auth::login($user);
        $request->session()->regenerateToken();

        event(new HandoffConsumed($user, $handoff->target_host));

        return redirect()->to($handoff->path)->header('Referrer-Policy', 'no-referrer');
    }

    /** Atomic: only one request can mark an unexpired token for this host as consumed. */
    private function claim(HandoffToken $handoff, Request $request): bool
    {
        return HandoffToken::query()
            ->whereKey($handoff->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('target_host', Str::lower($request->getHost()))
            ->update(['consumed_at' => now()]) === 1;
    }

    private function refuse(): RedirectResponse
    {
        return to_route('login')->with('status', __('This sign-in link is invalid or has expired.'));
    }
}
```

In `routes/web.php` (import the controller):

```php
// §6.4: any host; the token is bound to its target host.
Route::get('auth/handoff/{token}', HandoffController::class)
    ->middleware('throttle:handoff')
    ->name('auth.handoff');
```

Add `'auth.handoff'` to the exclusions in `tests/Feature/Tenancy/RouteHostMatrixTest.php`. Add `"This sign-in link is invalid or has expired."` to both lang files (nl: `"Deze inloglink is ongeldig of verlopen."`).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth/HandoffTest.php tests/Feature/Tenancy`
Expected: PASS. `TenantModelsTest` stays green: `handoff_tokens` has no `organisation_id`.

- [ ] **Step 7: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add a single-use cross-host handoff (§6.4)

- 60-second, host-bound, hashed tokens consumed atomically
- super admins to any host, members to their own host; relative paths only
- HandoffConsumed event for the audit log; expired tokens are prunable

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Recovery codes — copy, download, confirm before regenerating

**Files:**
- Modify: `resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.php`, `resources/views/pages/settings/two-factor/recovery-codes/recovery-codes.blade.php`, `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Settings/RecoveryCodesTest.php`

**Interfaces:**
- Consumes: Fortify `GenerateNewRecoveryCodes`.
- Produces: `downloadRecoveryCodes(): StreamedResponse` on the component (file `recovery-codes.txt`, one code per line); regenerate behind a `flux:modal` named `confirm-regenerate-recovery-codes`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Settings/RecoveryCodesTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function memberWithRecoveryCodes(): User
{
    $user = User::factory()->withTwoFactor()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    actingAs($user);

    return $user;
}

it('downloads the recovery codes as a text file', function (): void {
    memberWithRecoveryCodes();

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->call('downloadRecoveryCodes')
        ->assertFileDownloaded('recovery-codes.txt', 'recovery-code-1'.PHP_EOL);
});

it('offers to copy the codes and asks before regenerating them', function (): void {
    memberWithRecoveryCodes();

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->assertSee(__('Copy'))
        ->assertSeeHtml('navigator.clipboard.writeText(')
        ->assertSee(__('Regenerate recovery codes?'));
});

it('regenerates the codes after confirmation', function (): void {
    $user = memberWithRecoveryCodes();

    Livewire::test('pages::settings.two-factor.recovery-codes')->call('regenerateRecoveryCodes');

    expect(json_decode(decrypt((string) $user->fresh()?->two_factor_recovery_codes), true))->not->toBe(['recovery-code-1']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Settings/RecoveryCodesTest.php`
Expected: FAIL — `downloadRecoveryCodes` does not exist; no copy button or confirmation text.

- [ ] **Step 3: Component actions**

In `recovery-codes.php` (imports `Flux\Flux`, `Symfony\Component\HttpFoundation\StreamedResponse`):
- at the end of `regenerateRecoveryCodes()` add `Flux::modal('confirm-regenerate-recovery-codes')->close();`
- add:

```php
    /**
     * Download the recovery codes as a text file, one code per line (§4.2).
     */
    public function downloadRecoveryCodes(): StreamedResponse
    {
        $codes = implode(PHP_EOL, $this->recoveryCodes).PHP_EOL;

        return response()->streamDownload(function () use ($codes): void {
            echo $codes;
        }, 'recovery-codes.txt', ['Content-Type' => 'text/plain']);
    }
```

- [ ] **Step 4: Buttons and confirmation modal**

In `recovery-codes.blade.php`, replace the existing `@if (filled($recoveryCodes)) … Regenerate codes … @endif` button block with:

```blade
            @if (filled($recoveryCodes))
                <div x-show="showRecoveryCodes" class="flex flex-wrap gap-2">
                    <flux:button
                        icon="clipboard"
                        variant="filled"
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard.writeText(@js(implode(PHP_EOL, $recoveryCodes))); copied = true; setTimeout(() => copied = false, 2000)"
                    >
                        <span x-show="! copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </flux:button>

                    <flux:button icon="arrow-down-tray" variant="filled" wire:click="downloadRecoveryCodes">
                        {{ __('Download') }}
                    </flux:button>

                    <flux:modal.trigger name="confirm-regenerate-recovery-codes">
                        <flux:button icon="arrow-path" variant="filled">{{ __('Regenerate codes') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            @endif
```

and add, as the last element inside the component's root `<div>`:

```blade
    <flux:modal name="confirm-regenerate-recovery-codes" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Regenerate recovery codes?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Your current recovery codes stop working. Store the new codes before you leave this page.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="regenerateRecoveryCodes">{{ __('Regenerate codes') }}</flux:button>
            </div>
        </div>
    </flux:modal>
```

`flux:modal` is a free Flux component (not in the DEC-20 Pro-only list).

- [ ] **Step 5: Copy**

Add the new keys to both lang files (only keys that are not there yet; nl shown):

| Key | nl |
|---|---|
| `Cancel` | `Annuleren` |
| `Copied` | `Gekopieerd` |
| `Copy` | `Kopiëren` |
| `Download` | `Downloaden` |
| `Regenerate codes` | `Codes opnieuw genereren` |
| `Regenerate recovery codes?` | `Herstelcodes opnieuw genereren?` |
| `Your current recovery codes stop working. Store the new codes before you leave this page.` | `Je huidige herstelcodes werken dan niet meer. Bewaar de nieuwe codes voordat je deze pagina verlaat.` |

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Settings`
Expected: PASS.

- [ ] **Step 7: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: copy and download recovery codes, confirm before regenerating

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Follow-ups carried from M2

**Files:**
- Create: `tests/Fixtures/FailingCentralJob.php`
- Modify: `tests/Arch/TenancyRulesTest.php`, `app/Tenancy/Queue/JobTenancy.php`
- Test: `tests/Feature/Tenancy/QueueTenancyTest.php`

**Interfaces:**
- Consumes: M2's `JobTenancy`, `TenancyRulesTest`.
- Produces: the bypass rule also catches `suspendBypass(` / `resumeBypass(`, `withoutGlobalScope('…TenantScope')` and `withoutGlobalScope(new TenantScope)`; a SyncQueue-based job's (`sync`, `deferred`) `failed()` hook runs in the job's own tenancy context.

- [ ] **Step 1: Write the failing test**

`tests/Fixtures/FailingCentralJob.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class FailingCentralJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put('failed-hook.users', User::query()->count());
    }
}
```

Add to `tests/Feature/Tenancy/QueueTenancyTest.php` (import `Tests\Fixtures\FailingCentralJob`):

```php
it('runs the failed() hook of a synchronous tenantless job centrally', function (): void {
    config(['queue.default' => 'sync']);

    expect(fn () => Bus::dispatch(new FailingCentralJob))->toThrow(RuntimeException::class, 'boom');

    expect(Cache::get('failed-hook.users'))->toBeInt();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter='failed\(\) hook' tests/Feature/Tenancy/QueueTenancyTest.php`
Expected: FAIL — the sync driver raises `JobExceptionOccurred` (which already restored the outer, non-central context) before it calls `failed()`, so the hook's query throws `TenantNotResolvedException` instead of `boom`.

- [ ] **Step 3: Keep the job context until the sync driver has failed the job**

In `app/Tenancy/Queue/JobTenancy.php::end()`, add as the first statement (import `Illuminate\Queue\Jobs\SyncJob`):

```php
        // SyncQueue (and the deferred queue built on it) raises JobExceptionOccurred and then fails the job,
        // so the job's failed() hook still needs its context; JobFailed (fired after the hook) restores it.
        // A deleted job is not failed again (no JobFailed), so restore on JobExceptionOccurred then.
        if ($event instanceof JobExceptionOccurred && $event->job instanceof SyncJob && ! $event->job->isDeleted()) {
            return;
        }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy/QueueTenancyTest.php`
Expected: PASS (worker-path tests unchanged).

- [ ] **Step 5: Widen the bypass arch rule**

In `tests/Arch/TenancyRulesTest.php`, extend the pattern so it also matches `suspendBypass(`, `resumeBypass(`, `withoutGlobalScope('App\Tenancy\Scopes\TenantScope')` (string form) and `withoutGlobalScope(new TenantScope)`. Keep the existing alternatives and the allow-list. Prove each new form fails once: add it as a comment line to `routes/web.php`, run `php artisan test --compact tests/Arch/TenancyRulesTest.php`, see the rule fail naming `routes/web.php`, remove it. Confirm `git status` shows no leftovers.

- [ ] **Step 6: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "fix: run sync failed() hooks in the job context and widen the bypass rule

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Decision record, changelog and agent guidelines

**Files:**
- Modify: `docs/decisions.md`, `CHANGELOG.md`, `.ai/guidelines/skv3.md`

- [ ] **Step 1: Record DEC-27**

Append to `docs/decisions.md` under `## Build decisions`:

```markdown
### DEC-27 — Authentication build rulings (M3)

**Question:** Which choices made while building M3 refine the spec, and what is deferred?
**Answer:** (1) One `App\Tenancy\Auth\HostLoginRule` (active, not deleted, belongs to the current host; super admins everywhere) gates password sign-in (Fortify `authenticateUsing`, wrapped in a 200 ms Timebox and memoised on the request because Fortify calls it twice), the two-factor challenge (`UnscopedTwoFactorLoginRequest`) and passkeys (`Passkeys::authorizeLoginUsing`). An unknown address, a wrong password, an inactive user and a user on a foreign host fail identically. (2) The login and passkey limiters count failed attempts only, through `Limit::after()` (a response of 400 or more, or validation errors flashed by this request): login per address+IP (5/min) and per IP (20/min), passkeys per credential. Refinement of §4.2: two-factor (per challenge) and password confirmation (per user) count every attempt, because a failure-only limit is checked before and counted after the response, so concurrent requests could outrun it; login and passkeys keep failure-only counting with that residual risk. `password.email` and `password.confirm.store` are throttled by redefining Fortify's routes with the same names. (3) Passkeys: the relying-party id is `PASSKEYS_RELYING_PARTY_ID` ?: `TENANCY_APEX_DOMAIN` ?: the APP_URL host, set in `config/fortify.php` because Fortify copies `fortify.passkeys.*` into `passkeys.*` once at boot. The allowed origin is set per request in `InitializeTenancy` from the request origin — not in `TenantResolved`/`TenantForgotten` listeners as §6.2 says, because `Tenant::run()` inside a request switches tenants without changing the browser origin. (4) `UnscopedPasskey::user()` also loads deleted users so the host rule can refuse them (the package dispatches `PasskeyVerified` with the user before the authorisation check), and WebAuthn ceremony failures render as 422 on `credential` instead of 500. (5) Password reset links are built for `Hosts::homeOf($user)` (the admin host for super admins); every reset-link request answers the neutral `passwords.sent` in the same time, because the broker runs after the response through `defer()`; a reset token is refused off the user's own host. (6) `AuthenticateSession` runs in the web group after the membership check, so it also covers Livewire updates. (7) Email change: a 60-minute signed link (`signed:relative`, on the user's home host) carries a nonce whose hash is cached per user, so only the newest link works, and only once; the signed-in user must be the link's user; the old address gets a notice at request time, routed to the address itself (not the user model) so a late queue run cannot send it to the new address; `email_verified_at` is removed (verification is off by design). (8) Handoff tokens live in a central `handoff_tokens` table (sha256 hash, 60 s, target host, relative path), are consumed atomically by an update that must affect one row, always start a clean session (nothing carries over from an earlier user), and dispatch `HandoffConsumed` for the audit log. (9) A SyncQueue-based job's (`sync`, `deferred`) `failed()` hook runs in the job's context: JobTenancy ignores `JobExceptionOccurred` for a `SyncJob` that is not yet deleted, and `JobFailed` restores.
**Evidence:** `tests/Feature/Auth/*` (HostScopedLogin, LoginThrottle, Passkey with a software authenticator, PasswordResetHost, AuthenticateSession, Handoff), `tests/Feature/Settings/{EmailChange,RecoveryCodes,ProfileUpdate}Test.php`, `tests/Feature/Tenancy/QueueTenancyTest.php`; vendor reads of Fortify 1.41 (`AuthenticatedSessionController`, `RedirectIfTwoFactorAuthenticatable`, `FortifyServiceProvider::configurePasskeys`), laravel/passkeys 0.2.1 and web-auth/webauthn-lib 5.3 (`CheckAllowedOrigins`).
**Consequence:** Deferred: M4 — audit logging of sign-ins, failures, password resets, 2FA and passkey changes and `HandoffConsumed`; the organisations "Open" action and slug-rename redirect that issue handoffs. M5 — the passkeys module flag and its UI (§6.8), Dutch copy for the existing Fortify/Flux auth screens, error pages. M7 — the sessions list and "log out other devices" UI, scheduling `model:prune` for handoff tokens.
```

- [ ] **Step 2: Changelog**

Under `## [Unreleased]` → `### Added` in `CHANGELOG.md`:

```markdown
- Host-aware sign-in: password, two-factor and passkey sign-in only on hosts a user belongs to; failed attempts are rate limited.
- Passkeys that work on every subdomain, password reset links for the user's own host, email changes confirmed by link, and a single-use cross-host sign-in link.
```

- [ ] **Step 3: Teach the agents**

Append to the "Tenancy and authorization" section of `.ai/guidelines/skv3.md`:

```markdown
- Sign-in rules live in `App\Tenancy\Auth\HostLoginRule`; never re-implement "may this user sign in here".
- Links that leave the current request (mails, redirects to another host) are built for `Hosts::homeOf($user)`; signed links use `URL::temporarySignedRoute(..., absolute: false)` with `signed:relative`.
- Moving a signed-in user to another host goes through `App\Actions\Auth\IssueHandoff`; sessions are host-only.
```

Run: `php artisan boost:update`

- [ ] **Step 4: Run the checks**

Run: `composer check`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: record M3 rulings in DEC-27 and teach agents the sign-in rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Milestone gate

**Files:** none (verification, review and merge).

- [ ] **Step 1: Verify on both databases from a clean tree**

Run: `git status --short` → expected: empty.
Run: `composer check` → expected: green.
Run: `composer test:pgsql` → expected: green.

- [ ] **Step 2: Check traceability**

Run: `grep -L "covers §" $(git diff --name-only --diff-filter=A main -- 'tests/**/*.php' | grep -v -e Fixtures -e Support)`
Expected: no output.

- [ ] **Step 3: Check the spec coverage of M3**

Walk spec §4.2, §6.4 and Appendix A P1, P2, R1, E1 and confirm each bullet is implemented and tested in this branch or listed as deferred in DEC-27.

- [ ] **Step 4: Whole-branch review**

Use superpowers:requesting-code-review on `main...m3-authentication` with this plan and the spec sections as context. Apply verified findings with TDD, re-run Step 1.

- [ ] **Step 5: Merge after the owner's approval**

Ask the owner to approve the merge. After approval:

```bash
git switch main
git merge --no-ff m3-authentication -m "Merge M3: authentication

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git branch -d m3-authentication
```

Run: `composer check && composer test:pgsql` on `main` → expected: green.
