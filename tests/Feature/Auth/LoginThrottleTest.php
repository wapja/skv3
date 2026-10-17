<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use PragmaRX\Google2FA\Google2FA;

/**
 * Sends $size failing requests through the named limiter, each one arriving before the previous one is
 * answered, as a burst of parallel requests does, and returns how many of them reached the controller.
 */
function admittedInBurst(string $limiter, Request $request, int $size): int
{
    $middleware = app(ThrottleRequests::class);
    $admitted = 0;

    $send = function (int $left) use (&$send, &$admitted, $middleware, $request, $limiter): void {
        if ($left === 0) {
            return;
        }

        try {
            $middleware->handle($request, function () use (&$send, &$admitted, $left): Response {
                $admitted++;
                $send($left - 1);

                return new Response('', 422);
            }, $limiter);
        } catch (RuntimeException $exception) {
            // A throttled request: HttpResponseException when the limit has a response() callback.
            if (! $exception instanceof HttpResponseException && ! $exception instanceof ThrottleRequestsException) {
                throw $exception;
            }

            $send($left - 1);
        }
    };

    $send($size);

    return $admitted;
}

/** @param  array<string, mixed>  $input */
function burstRequest(string $uri, array $input = []): Request
{
    $request = Request::create($uri, 'POST', $input);
    $request->setLaravelSession(app('session')->driver());

    return $request;
}

/** Loads config/trustedproxy.php with TRUSTED_PROXIES set to $value (or unset when null). */
function trustedProxiesConfig(?string $value): mixed
{
    $saved = [$_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null];

    try {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        if ($value !== null) {
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;
        }

        return require config_path('trustedproxy.php');
    } finally {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        if ($saved[0] !== null) {
            $_ENV['TRUSTED_PROXIES'] = $saved[0];
        }

        if ($saved[1] !== null) {
            $_SERVER['TRUSTED_PROXIES'] = $saved[1];
        }
    }
}

// These tests count requests, not time: the Timebox would add half a second to every failed attempt, and
// a system sleep mid-test would expire the one-minute windows.
beforeEach(function (): void {
    Sleep::fake();
    $this->freezeTime();
});

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

it('does not count successful two-factor sign-ins (FU3-16)', function (): void {
    // Six recovery codes, one per sign-in: a TOTP code cannot be used twice in its window, a recovery code only once.
    $codes = array_map(fn (int $number): string => "recovery-code-{$number}", range(1, 6));
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create([
        'two_factor_recovery_codes' => encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
    ]);
    onTenant($organisation);

    // One more than the two-factor limit's five a minute, all in the frozen minute.
    foreach ($codes as $code) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['recovery_code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($member);
        $this->post(route('logout'));
    }
});

it('clears an address\'s failures once it signs in (G1-03)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
});

it('clears an address\'s failures once it signs in with a second factor (G1-03)', function (): void {
    $secret = app(Google2FA::class)->generateSecretKey();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);
    onTenant($organisation);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => (string) app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(route('dashboard'));
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
});

it('keeps the IP-wide failures when an account signs in (G1-03)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 19) as $attempt) {
        $this->post(route('login.store'), ['email' => "nobody{$attempt}@example.com", 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => 'nobody20@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')?->first('email'))->toStartWith('Too many login attempts');
});

it('keeps another address\'s failures when an account signs in from the same IP (G1-03)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $victim = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => $victim->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $victim->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $victim->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')?->first('email'))->toStartWith('Too many login attempts');
});

it('answers array input to the throttled auth routes with a validation error, not a server error (G1-02)', function (string $route, array $input, string $field): void {
    $organisation = Organisation::factory()->create();
    // A live reset record for the address, so an array token would reach the broker's token check.
    Password::broker()->createToken(User::factory()->for($organisation)->create(['email' => 'member@example.com']));
    onTenant($organisation);

    $this->from(route('login'))->post(route($route), $input)->assertRedirect()->assertSessionHasErrors($field);
})->with([
    'sign in' => ['login.store', ['email' => ['member@example.com'], 'password' => 'secret'], 'email'],
    'request a reset link' => ['password.email', ['email' => ['member@example.com']], 'email'],
    'reset the password' => ['password.update', ['token' => 'x', 'email' => ['member@example.com'], 'password' => 'new-password', 'password_confirmation' => 'new-password'], 'email'],
    'reset the password (token)' => ['password.update', ['token' => ['x'], 'email' => 'member@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password'], 'token'],
]);

it('answers an array password to the password confirmation with a validation error, not a server error', function (): void {
    // Fortify's ConfirmPassword takes ?string $password and got the array from $request->input('password').
    $organisation = Organisation::factory()->create();
    asMember($organisation);

    $this->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => ['password']])
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('answers an array credential id to the passkey sign-in with a validation error, not a server error (G1-02)', function (): void {
    usePasskeysModule(true);
    onTenant(Organisation::factory()->create());

    $this->postJson(route('passkey.login'), ['credential' => ['id' => ['x']]])->assertUnprocessable();
});

it('sends a throttled sign-in back to the login page, never to the Referer (CD-13)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'], ['Referer' => 'https://evil.example/phish'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
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

it('bounds a burst of parallel failed sign-ins for one address', function (): void {
    $request = burstRequest('http://acme.localhost/login', ['email' => 'member@example.com', 'password' => 'wrong']);

    expect(admittedInBurst('login', $request, 50))->toBe(30);
});

it('bounds a burst of parallel two-factor guesses for one challenge', function (): void {
    $request = burstRequest('http://acme.localhost/two-factor-challenge', ['code' => '000000']);
    $request->session()->put('login.id', 1);

    expect(admittedInBurst('two-factor', $request, 50))->toBe(10);
});

it('bounds a burst of parallel passkey attempts from one IP address', function (): void {
    usePasskeysModule(true);
    $request = burstRequest('http://acme.localhost/passkeys/login');

    expect(admittedInBurst('passkeys', $request, 50))->toBe(30);
});

it('limits failed passkey sign-ins per IP, whatever credential id the client sends (G1-05)', function (): void {
    usePasskeysModule(true);
    onTenant(Organisation::factory()->create());

    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('passkey.login'), ['credential' => ['id' => "credential-{$attempt}"]])->assertUnprocessable();
    }

    $this->postJson(route('passkey.login'), ['credential' => ['id' => 'credential-11']])->assertTooManyRequests();

    // CD-6: a throttled request is not a sign-in attempt, so the 429 adds no audit entry.
    expect(recordedActivity(ActivityEvent::LoginFailed))->toHaveCount(10);
});

it('limits failed passkey confirmations per user, not per IP (G1-05)', function (): void {
    usePasskeysModule(true);
    $organisation = Organisation::factory()->create();
    $ada = User::factory()->for($organisation)->create();
    $bob = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $this->actingAs($ada);
    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('passkey.confirm'), ['credential' => ['id' => "credential-{$attempt}"]])->assertUnprocessable();
    }
    $this->postJson(route('passkey.confirm'), ['credential' => ['id' => 'credential-11']])->assertTooManyRequests();

    $this->actingAs($bob);
    $this->postJson(route('passkey.confirm'), ['credential' => ['id' => 'credential-12']])->assertUnprocessable();
});

it('trusts the proxies named in TRUSTED_PROXIES and none by default', function (): void {
    expect(trustedProxiesConfig(null))->toBe(['proxies' => null])
        ->and(trustedProxiesConfig(''))->toBe(['proxies' => null])
        ->and(trustedProxiesConfig('10.0.0.1,10.0.0.2'))->toBe(['proxies' => '10.0.0.1,10.0.0.2']);
});

it('limits each client behind a trusted proxy by its own address', function (): void {
    config(['trustedproxy' => trustedProxiesConfig('127.0.0.1')]);
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach (range(1, 20) as $attempt) {
        $this->post(route('login.store'), ['email' => "nobody{$attempt}@example.com", 'password' => 'wrong'], ['X-Forwarded-For' => '203.0.113.10']);
    }

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'], ['X-Forwarded-For' => '203.0.113.20'])
        ->assertRedirect(route('dashboard'));
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
