<?php

declare(strict_types=1);

/** covers §4.3, §6.2, §6.4 */

use App\Models\HandoffToken;
use App\Models\Organisation;
use Livewire\Livewire;

it('edits the own organisation as its admin', function (): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Holding', 'slug' => 'acme']);
    asOrgAdmin($acme);

    $this->get(route('organisations.edit', $acme->id))->assertOk()->assertSee('Acme Holding');
    $this->get(route('organisations.index'))
        ->assertOk()
        ->assertSee('data-test="edit-organisation-'.$acme->id.'"', escape: false)
        ->assertDontSee('data-test="new-organisation-button"', escape: false);

    Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])
        ->assertSet('organisationId', $acme->id)
        ->set('name', 'Acme Renamed')
        ->set('description', 'Now with a description')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertDispatched('toast-show');

    expect($acme->fresh()?->name)->toBe('Acme Renamed')
        ->and($acme->fresh()?->description)->toBe('Now with a description')
        ->and($acme->fresh()?->slug)->toBe('acme');
});

it('answers 404 for another organisation\'s id on a tenant host', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['name' => 'Beta Works', 'slug' => 'beta']);

    asOrgAdmin($acme);
    $this->get(route('organisations.edit', $beta->id))->assertNotFound();

    asSuperAdmin();
    onTenant($acme);
    $this->get(route('organisations.edit', $beta->id))->assertNotFound();

    onAdminHost();
    $this->get(route('organisations.edit', $beta->id))->assertOk()->assertSee('Beta Works');
});

it('moves the editor to the new host through a handoff after a slug rename on that host (ORG-16)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = asSuperAdmin();
    onTenant($acme);

    $page = Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])
        ->set('slug', 'acme-new')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirectContains('http://acme-new.localhost/auth/handoff/');

    $handoff = HandoffToken::query()->sole();
    expect($acme->fresh()?->slug)->toBe('acme-new')
        ->and($handoff->user_id)->toBe($superAdmin->id)
        ->and($handoff->target_host)->toBe('acme-new.localhost')
        ->and($handoff->path)->toBe('/organisations/'.$acme->id.'/edit');

    $this->get((string) $page->__get('effects')['redirect'])
        ->assertRedirect('http://acme-new.localhost/organisations/'.$acme->id.'/edit');
    $this->assertAuthenticatedAs($superAdmin);
});

it('stays on the admin host after a rename there', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])
        ->set('slug', 'acme-new')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSet('slug', 'acme-new')
        ->assertDispatched('toast-show');

    expect($acme->fresh()?->slug)->toBe('acme-new')
        ->and(HandoffToken::query()->count())->toBe(0);
});

it('shows the slug read-only to organisation admins', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    $this->get(route('organisations.edit', $acme->id))
        ->assertOk()
        ->assertSee('data-test="organisation-slug-readonly"', escape: false)
        ->assertSee('acme.localhost')
        ->assertDontSee('wire:model="slug"', escape: false);

    Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])
        ->set('slug', 'hijacked')
        ->set('name', 'Acme Renamed')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect($acme->fresh()?->slug)->toBe('acme')
        ->and($acme->fresh()?->name)->toBe('Acme Renamed')
        ->and(HandoffToken::query()->count())->toBe(0);
});

it('has no second-factor setting yet (RL-17)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('organisations.edit', $acme->id))
        ->assertOk()
        ->assertDontSee('second_factor', escape: false)
        ->assertDontSee('secondFactor', escape: false);

    $component = Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])->instance();

    expect(property_exists($component, 'requireSecondFactor'))->toBeFalse()
        ->and(property_exists($component, 'require_second_factor'))->toBeFalse();
});
