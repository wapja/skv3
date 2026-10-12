<?php

declare(strict_types=1);

/** covers §4.11, §6.5 */

use App\Authorization\Role;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;

it('renders the sidebar buttons and the pagination in each request\'s language', function (): void {
    // Blaze folds some Flux components into the compiled view and runs their __() calls then, so the first language
    // to compile would win for everyone. The kit passes those labels at runtime; pagination renders at runtime.
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    User::factory()->for($organisation)->count(30)->create();
    onTenant($organisation);
    $dutch = asOrgAdmin($organisation);
    $dutch->forceFill(['locale' => Locale::Dutch])->save();
    $english = User::factory()->for($organisation)->create(['locale' => Locale::English]);
    $english->assignRole(Role::OrganisationAdmin->value);

    $this->actingAs($dutch)->get(route('users.index'))->assertOk()
        ->assertSee('aria-label="Zijbalk in- of uitklappen"', false)
        ->assertSee('Zijbalk in- of uitklappen')
        ->assertSee('Toont');

    $this->actingAs($english)->get(route('users.index'))->assertOk()
        ->assertSee('aria-label="Toggle sidebar"', false)
        ->assertDontSee('Zijbalk in- of uitklappen')
        ->assertSee('Showing');
});
