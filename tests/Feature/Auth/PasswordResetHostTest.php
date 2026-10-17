<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

// M7c T2 #4: TrustProxies sets Symfony's trusted proxies statically, so the proxy case would leave 127.0.0.1 trusted
// for every later request built outside the middleware. Trust none again, with TrustProxies' own header set.
afterEach(function (): void {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB);
});

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

it('builds the reset link with APP_URL\'s scheme and port, whatever the request says (CD-4, G1-04)', function (string $case): void {
    Notification::fake();
    config(['app.url' => 'https://localhost']);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    if ($case === 'a port in the Host header') {
        $this->post('http://acme.localhost:6666/forgot-password', ['email' => $member->email])->assertSessionHasNoErrors();
    } else {
        // M7c T2 #1: the request must really see the proxy's scheme and port, or APP_URL beats nothing.
        $seen = null;
        Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$seen): void {
            $seen = ['port' => $event->request->getPort(), 'secure' => $event->request->isSecure()];
        });
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->post(route('password.email'), ['email' => $member->email], ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '8080'])
            ->assertSessionHasNoErrors();

        expect($seen)->toBe(['port' => 8080, 'secure' => false]);
    }

    Notification::assertSentTo($member, ResetPassword::class, fn (ResetPassword $notification): bool => str_starts_with(
        (string) $notification->toMail($member)->actionUrl,
        'https://acme.localhost/reset-password/',
    ));
})->with(['a port in the Host header', 'forwarded headers from a trusted proxy']);

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

it('sends the reset link only after the response is handled', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $sentWhenHandled = null;
    Event::listen(RequestHandled::class, function () use ($member, &$sentWhenHandled): void {
        $sentWhenHandled = Notification::sent($member, ResetPassword::class)->count();
    });

    $this->post(route('password.email'), ['email' => $member->email]);

    expect($sentWhenHandled)->toBe(0)
        ->and(Notification::sent($member, ResetPassword::class))->toHaveCount(1);
});

it('throttles reset-link requests per address and per IP address', function (string $limit): void {
    Sleep::fake(); // counts requests, not time
    $this->freezeTime(); // a system sleep mid-test must not expire the one-minute window
    Notification::fake();
    onApex();
    $request = fn (int $number): TestResponse => $this->post(route('password.email'), [
        'email' => $limit === 'address' ? 'someone@example.com' : "someone-{$number}@example.com",
    ]);
    $allowed = $limit === 'address' ? 5 : 20;

    foreach (range(1, $allowed) as $number) {
        $request($number)->assertRedirect();
    }

    $request($allowed + 1)->assertTooManyRequests();
})->with(['address', 'IP address']);

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

it('refuses a reset token for a user who may not sign in', function (string $state): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->for($organisation)->{$state}()->create();
    $token = Password::broker()->createToken($user);
    onTenant($organisation);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', (string) $user->fresh()?->password))->toBeTrue();
})->with(['pending', 'disabled']);

it('answers a bad reset token the same way for known and unknown addresses (R1)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    foreach ([$member->email, 'nobody@example.com'] as $email) {
        $this->post(route('password.update'), [
            'token' => 'not-a-valid-token',
            'email' => $email,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertSessionHasErrors(['email' => __('passwords.token')]);
    }
});

it('throttles password resets per address and per IP address', function (string $limit): void {
    Sleep::fake(); // counts requests, not time
    $this->freezeTime(); // a system sleep mid-test must not expire the one-minute window
    onApex();
    $attempt = fn (int $number): TestResponse => $this->post(route('password.update'), [
        'token' => 'not-a-valid-token',
        'email' => $limit === 'address' ? 'someone@example.com' : "someone-{$number}@example.com",
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ]);
    $allowed = $limit === 'address' ? 5 : 20;

    foreach (range(1, $allowed) as $number) {
        $attempt($number)->assertRedirect();
    }

    $attempt($allowed + 1)->assertTooManyRequests();
})->with(['address', 'IP address']);

it('has a neutral Dutch reset message', function (): void {
    app()->setLocale('nl');

    expect(__('passwords.sent'))->toBe('Als dit e-mailadres bij ons bekend is, hebben we je een link gestuurd om je wachtwoord opnieuw in te stellen.');
});
