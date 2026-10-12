<?php

declare(strict_types=1);

/** covers §4.5, §7.2 */

use App\Models\Organisation;

it('lets an invitee choose a name and password and lands them on the dashboard', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme', 'name' => 'Acme Corp']);
    $invitation = inviteMember($organisation, email: 'new@example.com');
    // The link is signed relative (signed:relative), so its path and query stay valid on the browser's port.
    $path = parse_url(acceptLinkFor($invitation), PHP_URL_PATH).'?'.parse_url(acceptLinkFor($invitation), PHP_URL_QUERY);

    visit($path)->withHost('acme.localhost')
        ->assertSee(__('Welcome to :organisation', ['organisation' => 'Acme Corp']))
        ->fill('name', 'New Person')
        ->fill('password', 'a-strong-password-1')
        ->fill('password_confirmation', 'a-strong-password-1')
        ->press('@accept-invitation-button')
        ->assertPathIs('/dashboard')
        ->assertSee('New Person')
        ->assertNoJavaScriptErrors();
});
