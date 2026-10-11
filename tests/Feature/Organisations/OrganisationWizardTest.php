<?php

declare(strict_types=1);

/** covers §4.3, §6.2 */

use App\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('suggests a slug from the name until the slug is edited', function (): void {
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::organisations.create')
        ->set('name', 'Café Noord B.V.')
        ->assertSet('slug', 'cafe-noord-bv')
        ->assertSet('slugEdited', false)
        ->set('slug', 'Bakery')
        ->assertSet('slug', 'bakery')
        ->assertSet('slugEdited', true)
        ->set('name', 'Something Else')
        ->assertSet('slug', 'bakery');
});

it('shows availability and the slug.apex preview while typing', function (): void {
    Organisation::factory()->create(['slug' => 'taken']);
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::organisations.create')
        ->set('slug', 'fresh-start')
        ->assertSee('fresh-start.localhost')
        ->assertSee(__('This address is available.'))
        ->assertSeeHtml('data-test="slug-available"')
        ->set('slug', 'taken')
        ->assertSee('taken.localhost')
        ->assertDontSee(__('This address is available.'))
        ->assertSeeHtml('data-test="slug-unavailable"')
        ->set('slug', 'admin')
        ->assertSeeHtml('data-test="slug-unavailable"');
});

it('creates the organisation, invites the first admin and returns to the index with a toast', function (): void {
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::organisations.create')
        ->assertSet('adminLocale', 'en')
        ->set('name', 'Acme Holding')
        ->set('slug', 'acme')
        ->set('description', 'Our first customer')
        ->set('adminEmail', 'First.Admin@Example.com')
        ->set('adminLocale', 'nl')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('organisations.index'));

    $organisation = Organisation::query()->where('slug', 'acme')->sole();
    $firstAdmin = DB::table('users')->where('email', 'first.admin@example.com')->first();

    expect(session('status'))->toBe(__('Organisation :name created. Its first admin has been invited.', ['name' => 'Acme Holding']))
        ->and($organisation->name)->toBe('Acme Holding')
        ->and($organisation->description)->toBe('Our first customer')
        ->and($firstAdmin?->organisation_id)->toEqual($organisation->id)
        ->and($firstAdmin?->status)->toBe('pending_activation')
        ->and($firstAdmin?->locale)->toBe('nl')
        ->and(DB::table('invitations')->where('organisation_id', $organisation->id)->count())->toBe(1);
});

it('answers 404 on a tenant host', function (): void {
    $organisation = Organisation::factory()->create();
    asSuperAdmin();

    onTenant($organisation);
    $this->get(route('organisations.create'))->assertNotFound();

    onAdminHost();
    $this->get(route('organisations.create'))->assertOk()->assertSee(__('New organisation'));
    $this->get(route('organisations.index'))->assertOk()->assertSee('data-test="new-organisation-button"', escape: false);
});
