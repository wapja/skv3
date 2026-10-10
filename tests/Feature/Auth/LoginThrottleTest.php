<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Sleep;

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

// These tests count requests, not time: the Timebox would add half a second to every failed attempt.
beforeEach(function (): void {
    Sleep::fake();
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
    $request = burstRequest('http://acme.localhost/passkeys/login');

    expect(admittedInBurst('passkeys', $request, 50))->toBe(30);
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
