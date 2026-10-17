<?php

declare(strict_types=1);

/** covers §4.11, §6.5, §7.2 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;

it('switches the language from the user menu and keeps the filters chosen on the page (G3-07)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = browserUser($organisation, 'admin@example.com');
    $admin->forceFill(['locale' => Locale::Dutch])->save();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));

    $page = visit('/login')->withHost('acme.localhost')
        ->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard');

    // The filter changes after the page loaded: only the browser's own URL still knows it.
    $page->navigate(sameOrigin($page->url(), '/activity'))
        ->assertSee('Activiteitenlog')
        ->select('@filter-event', ActivityEvent::Login->value)
        // Retried until the timeout like every awaitable assertion, so the Livewire round trip may finish first.
        ->assertQueryStringHas('event', ActivityEvent::Login->value)
        ->click('@sidebar-menu-button')
        ->click('@user-menu-locale-en')
        ->assertSee('Activity log')
        ->assertPathIs('/activity')
        ->assertQueryStringHas('event', ActivityEvent::Login->value)
        ->assertNoJavaScriptErrors();

    expect($admin->fresh()?->locale)->toBe(Locale::English);
});
