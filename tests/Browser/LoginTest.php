<?php

declare(strict_types=1);

/** covers §4.2, §7.2 */

use App\Enums\Appearance;
use App\Models\Organisation;

it('signs a member in on their organisation\'s host (spike: {slug}.localhost reaches the in-process server)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = browserUser($organisation);

    $page = visit('/login')->withHost('acme.localhost')
        ->fill('email', $member->email)
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/dashboard')
        ->assertSee($member->name)
        ->assertNoJavaScriptErrors();

    // Pins the navigate rule (RL-2): an absolute URL on the current origin keeps the host-only session.
    $page->navigate(sameOrigin($page->url(), '/settings/profile'))
        ->assertPathIs('/settings/profile')
        ->assertPresent('@update-profile-button');
});

it('signs a super admin in on the admin host', function (): void {
    $admin = browserUser(email: 'root@example.com', superAdmin: true);

    visit('/login')->withHost('admin.localhost')
        ->fill('email', $admin->email)
        ->fill('password', 'password')
        ->press('@login-button')
        // Fortify sends every user to fortify.home (/dashboard); only `/` routes central super admins to organisations.
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('refuses a member on another organisation\'s host with the generic error', function (): void {
    Organisation::factory()->create(['slug' => 'other']);
    $member = browserUser(Organisation::factory()->create(['slug' => 'acme']));

    visit('/login')->withHost('other.localhost')
        ->fill('email', $member->email)
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/login')
        ->assertSee(__('auth.failed'));
});

it('saves the appearance chosen on the settings page (DEC-12)', function (): void {
    $member = browserUser(Organisation::factory()->create(['slug' => 'acme']));

    $page = visit('/login')->withHost('acme.localhost')
        ->fill('email', $member->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard');

    // RL-2: after the first page, navigate to an absolute URL on the current origin.
    $page->navigate(sameOrigin($page->url(), '/settings/appearance'))
        // The user menu also lists "Dark"; target the settings page's segmented switch.
        ->click('[data-flux-radio-segmented][value="dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertNoJavaScriptErrors();

    // The user menu saves the choice in a Livewire request of its own: wait for it, at most five seconds (A78).
    for ($tries = 0; $member->fresh()?->appearance !== Appearance::Dark && $tries < 50; $tries++) {
        $page->wait(0.1);
    }

    expect($member->fresh()?->appearance)->toBe(Appearance::Dark);
});
