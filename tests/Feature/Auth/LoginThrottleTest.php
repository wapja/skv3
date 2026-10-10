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
