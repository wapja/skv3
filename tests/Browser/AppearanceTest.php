<?php

declare(strict_types=1);

/** covers §6.5 (DEC-12), §7.2 */

use App\Enums\Appearance;
use App\Models\Organisation;

it('keeps a super admin\'s dark choice when they open an organisation on its own host', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme', 'name' => 'Acme Corp']);
    $admin = browserUser(email: 'root@example.com', superAdmin: true);

    $page = visit('/login')->withHost('admin.localhost')
        ->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard');

    $page->navigate(sameOrigin($page->url(), '/settings/appearance'))
        // The user menu also lists "Dark"; target the settings page's segmented switch.
        ->click('[data-flux-radio-segmented][value="dark"]')
        ->wait(1);

    expect($admin->fresh()?->appearance)->toBe(Appearance::Dark);

    $page->navigate(sameOrigin($page->url(), '/organisations'))
        ->click("@open-organisation-{$organisation->id}")
        // "Your roles" is on the dashboard only: it waits for the handoff, as the index also lists "Acme Corp".
        ->assertSee(__('Your roles'))
        ->assertHostIs('acme.localhost')
        ->assertPathIs('/dashboard')
        ->assertSee('Acme Corp')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('localStorage.getItem("flux.appearance")', 'dark');
});
