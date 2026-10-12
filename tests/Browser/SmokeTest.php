<?php

declare(strict_types=1);

/** covers §6.5, §7.2 */

use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;

it('renders the main pages without JavaScript errors, in light and dark', function (bool $dark): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = browserUser($organisation, 'admin@example.com');
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));

    $page = visit('/login')->withHost('acme.localhost');
    if ($dark) {
        $page = $page->inDarkMode();
    }

    $page->fill('email', $admin->email)->fill('password', 'password')->press('@login-button');

    foreach (['/dashboard', '/users', '/invitations/pending', '/roles', '/activity', '/settings/profile', '/settings/appearance'] as $path) {
        // assertPathIs proves the page rendered on this host, not a login redirect or a 404 on 127.0.0.1.
        $page->navigate(sameOrigin($page->url(), $path))->assertPathIs($path)->assertNoJavaScriptErrors();
    }
})->with(['light' => false, 'dark' => true]);
