<?php

declare(strict_types=1);

/** covers §4.7.4, §7.2 */

use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;

it('starts an impersonation with a reason, shows the banner and stops it', function (): void {
    useImpersonationModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = browserUser($organisation, 'admin@example.com');
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $target = browserUser($organisation, 'target@example.com');

    $page = visit('/login')->withHost('acme.localhost')
        ->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard');

    $page->navigate(sameOrigin($page->url(), '/users'))
        ->click("@impersonate-{$target->id}")
        ->fill('impersonationReason', 'Support ticket 42')
        ->press('@start-impersonating')
        ->assertSee(__('Logged in as :name', ['name' => $target->name]))
        ->press('@stop-impersonating')
        ->assertPathIs('/users')
        ->assertMissing('@impersonation-banner')
        ->assertNoJavaScriptErrors();
});
