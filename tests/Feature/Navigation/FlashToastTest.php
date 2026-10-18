<?php

declare(strict_types=1);

/** covers §4.10.1, §6.5 */

use App\Models\Organisation;

it('turns a status flash into a toast on app pages', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-flash-toast', false);

    $this->withSession(['status' => 'Welcome, your account is ready.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-flash-toast', false)
        ->assertSee('$flux.toast', false)
        ->assertSee('Welcome, your account is ready.');
});

// The auth pages show the status inline, where it stays visible and is read with the page; the bridge stays off there.
it('shows a status on the auth pages once, inline and not as a toast', function (string $route): void {
    onTenant(Organisation::factory()->create(['slug' => 'acme']));

    $content = (string) $this->withSession(['status' => 'Status probe message.'])
        ->get(route($route))
        ->assertOk()
        ->assertDontSee('data-flash-toast', false)
        ->getContent();

    expect(substr_count($content, 'Status probe message.'))->toBe(1);
})->with(['login', 'password.request']);

// Fails on main: the profile page renders the status flash as a callout.
it('shows the profile page\'s status as a toast instead of a callout', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->withSession(['status' => 'Your email address has been changed.'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-flash-toast', false)
        ->assertDontSee('data-flux-callout', false);
});
