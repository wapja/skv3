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
