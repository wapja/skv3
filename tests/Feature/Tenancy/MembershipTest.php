<?php

declare(strict_types=1);

/** covers §4.1 */

use App\Models\Organisation;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('lets a member in on their own organisation host', function (): void {
    asMember(Organisation::factory()->create());

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticated();
});

it('logs a member out on another organisation host', function (): void {
    asMember(Organisation::factory()->create());
    onTenant(Organisation::factory()->create());

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account does not have access to this address. Sign in at your own organisation.');
    $this->assertGuest();
});

it('logs a member out on the central hosts', function (string $host): void {
    asMember(Organisation::factory()->create());
    $host === 'apex' ? onApex() : onAdminHost();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['apex', 'admin']);

it('logs out users that are not active', function (string $state): void {
    $organisation = Organisation::factory()->create();
    actingAs(User::factory()->for($organisation)->{$state}()->create());
    onTenant($organisation);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account is not active. Contact your administrator.');
    $this->assertGuest();
})->with(['pending', 'disabled']);

it('lets an active super admin in on every host', function (string $host): void {
    asSuperAdmin();
    match ($host) {
        'tenant' => onTenant(Organisation::factory()->create()),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    $this->get(route('dashboard'))->assertOk();
})->with(['tenant', 'apex', 'admin']);

it('logs out a disabled super admin', function (): void {
    actingAs(User::factory()->superAdmin()->disabled()->create());
    onAdminHost();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('uses host-only session cookies', function (): void {
    expect(config('session.domain'))->toBeNull();
});

it('applies the host login rule to members (FU3-6)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $member->delete();
    actingAs($member);
    onTenant($organisation);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account is not active. Contact your administrator.');
    $this->assertGuest();
});
