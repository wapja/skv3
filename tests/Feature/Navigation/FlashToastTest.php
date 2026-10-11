<?php

declare(strict_types=1);

/** covers §6.5 */

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

// Fails on main: the profile page renders the status flash as a callout.
it('shows the profile page\'s status as a toast instead of a callout', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->withSession(['status' => 'Your email address has been changed.'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-flash-toast', false)
        ->assertDontSee('data-flux-callout', false);
});
