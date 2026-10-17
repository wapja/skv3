<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Authorization\SetSuperAdmin;
use App\Models\Organisation;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;

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

it('pads every failed sign-in to the same minimum time and lets a success return at once', function (): void {
    Sleep::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onApex();

    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    Sleep::assertSleptTimes(2);

    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    Sleep::assertSleptTimes(2);
});

it('pads failed sign-ins to auth.timebox_duration, 500 ms unless configured otherwise', function (): void {
    expect(config('auth.timebox_duration'))->toBe(500_000);

    // The box sleeps what the sign-in left of the duration, and the sign-in runs inside the request: measuring the
    // request bounds the sleep without guessing how long a sign-in takes under load. The fake sleeps return at once.
    Sleep::fake();
    onApex();
    config(['auth.timebox_duration' => 30_000_000]);

    $started = microtime(true);
    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $elapsed = (int) ceil((microtime(true) - $started) * 1_000_000);

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMicroseconds >= 30_000_000 - $elapsed - 1 && $duration->totalMicroseconds <= 30_000_000);
});

it('hashes the password for an unknown address too, so a failed sign-in costs one hash either way', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $hasher = Mockery::mock(HashManager::class, [app()])->makePartial();
    Hash::swap($hasher);

    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    $hasher->shouldHaveReceived('make')->once();
    $hasher->shouldNotHaveReceived('check');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $hasher->shouldHaveReceived('make')->once();
    $hasher->shouldHaveReceived('check')->once();
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

it('refuses a two-factor challenge for a super admin demoted before the code, on the admin host (§4.2, DEC-28)', function (): void {
    // A promoted member of acme: the demotion keeps them active and in acme, so only the host rule refuses the code.
    $organisation = Organisation::factory()->create();
    User::factory()->superAdmin()->create();
    $superAdmin = User::factory()->for($organisation)->withTwoFactor()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);
    onAdminHost();
    $this->post(route('login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    app(SetSuperAdmin::class)->handle($superAdmin, false);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect($superAdmin->fresh()?->isActive())->toBeTrue();
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
