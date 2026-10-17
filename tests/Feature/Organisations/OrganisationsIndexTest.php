<?php

declare(strict_types=1);

/** covers §4.3.2, §4.3.3, §4.3.7, §6.2 */

use App\Models\HandoffToken;
use App\Models\Organisation;
use Livewire\Livewire;

it('shows only the own organisation on a tenant host (ORG-3)', function (): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Holding', 'slug' => 'acme']);
    Organisation::factory()->create(['name' => 'Beta Works', 'slug' => 'beta']);

    asOrgAdmin($acme);
    $this->get(route('organisations.index'))
        ->assertOk()
        ->assertSee('Acme Holding')
        ->assertDontSee('Beta Works')
        ->assertDontSee('data-test="organisations-view"', escape: false)
        ->assertDontSee('data-test="open-organisation-', escape: false);

    asSuperAdmin();
    onTenant($acme);
    $this->get(route('organisations.index'))
        ->assertOk()
        ->assertSee('Acme Holding')
        ->assertDontSee('Beta Works');
});

it('lists every organisation for a super admin and switches to the archived view through the URL (ORG-7)', function (): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Holding', 'slug' => 'acme']);
    Organisation::factory()->create(['name' => 'Beta Works', 'slug' => 'beta']);
    $gamma = Organisation::factory()->create(['name' => 'Gamma Archive', 'slug' => 'gamma']);
    $gamma->delete();
    asSuperAdmin();
    onAdminHost();

    $this->get(route('organisations.index'))
        ->assertOk()
        ->assertSee('Acme Holding')
        ->assertSee('Beta Works')
        ->assertSee('acme.localhost')
        ->assertDontSee('Gamma Archive')
        ->assertSee('data-test="organisations-view"', escape: false)
        ->assertSee('data-test="open-organisation-'.$acme->id.'"', escape: false);

    $this->get(route('organisations.index', ['view' => 'archived']))
        ->assertOk()
        ->assertSee('Gamma Archive')
        ->assertDontSee('Acme Holding')
        ->assertSee('data-test="restore-organisation-'.$gamma->id.'"', escape: false)
        ->assertSee('data-test="purge-organisation-'.$gamma->id.'"', escape: false);

    $this->get(route('organisations.index', ['search' => 'BETA']))
        ->assertOk()
        ->assertSee('Beta Works')
        ->assertDontSee('Acme Holding');
});

it('opens an organisation on its own host through a handoff (ORG-18)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::organisations.index')
        ->call('open', $organisation->id)
        ->assertRedirectContains('http://acme.localhost/auth/handoff/');

    $handoff = HandoffToken::query()->sole();
    expect($handoff->user_id)->toBe($superAdmin->id)
        ->and($handoff->target_host)->toBe('acme.localhost')
        ->and($handoff->path)->toBe('/dashboard');

    $this->get((string) $page->__get('effects')['redirect'])->assertRedirect('http://acme.localhost/dashboard');
    $this->assertAuthenticatedAs($superAdmin);
    expect($handoff->fresh()?->consumed_at)->not->toBeNull();
});

it('refuses to open an archived organisation', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $organisation->delete();
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::organisations.index')
        ->call('open', $organisation->id)
        ->assertNotFound();

    expect(HandoffToken::query()->count())->toBe(0);
});

it('archives, restores and purges from the index with confirmations (ORG-6..10)', function (): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme Holding', 'slug' => 'acme']);
    asSuperAdmin();
    onAdminHost();
    session()->put('auth.password_confirmed_at', time());

    $page = Livewire::test('pages::organisations.index')
        ->call('confirmArchive', $organisation->id)
        ->assertSet('archivingId', $organisation->id)
        ->assertDispatched('modal-show', name: 'archive-organisation')
        ->call('archive')
        ->assertHasNoErrors()
        ->assertSet('archivingId', null)
        ->assertDispatched('modal-close', name: 'archive-organisation');

    expect(Organisation::onlyTrashed()->whereKey($organisation->id)->exists())->toBeTrue();

    $page->set('view', 'archived')
        ->assertSee('Acme Holding')
        ->call('restore', $organisation->id)
        ->assertHasNoErrors();

    expect(Organisation::query()->whereKey($organisation->id)->exists())->toBeTrue();

    $page->set('view', 'active')
        ->call('confirmArchive', $organisation->id)
        ->call('archive')
        ->set('view', 'archived')
        ->call('confirmPurge', $organisation->id)
        ->assertNoRedirect()
        ->assertSet('purgingId', $organisation->id)
        ->assertDispatched('modal-show', name: 'purge-organisation')
        ->set('purgeConfirmation', 'wrong')
        ->call('purge')
        ->assertHasErrors(['purgeConfirmation']);

    expect(Organisation::withTrashed()->whereKey($organisation->id)->exists())->toBeTrue();

    $page->set('purgeConfirmation', 'acme')
        ->call('purge')
        ->assertHasNoErrors()
        ->assertSet('purgingId', null)
        ->assertDispatched('modal-close', name: 'purge-organisation');

    expect(Organisation::withTrashed()->whereKey($organisation->id)->exists())->toBeFalse();
});

it('asks for a fresh password confirmation before purging and comes back to the archived view (RL-18)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $organisation->delete();
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::organisations.index')
        ->call('confirmPurge', $organisation->id)
        ->assertRedirect(route('password.confirm'))
        ->assertSet('purgingId', null);

    expect(session('url.intended'))->toBe(route('organisations.index', ['view' => 'archived'], absolute: false));

    // A confirmation that expires while the modal is open is asked for again before anything is deleted.
    session()->put('auth.password_confirmed_at', time());
    $page = Livewire::test('pages::organisations.index')
        ->call('confirmPurge', $organisation->id)
        ->assertNoRedirect()
        ->set('purgeConfirmation', 'acme');
    session()->put('auth.password_confirmed_at', time() - (int) config('auth.password_timeout') - 1);

    $page->call('purge')->assertRedirect(route('password.confirm'));

    expect(Organisation::onlyTrashed()->whereKey($organisation->id)->exists())->toBeTrue();
});

it('refuses forged archive, restore, purge or open ids from a tenant host', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $gamma = Organisation::factory()->create(['slug' => 'gamma']);
    $gamma->delete();

    asOrgAdmin($acme);
    $snapshot = pageSnapshot(route('organisations.index'), 'pages::organisations.index');

    callPageAction($snapshot, 'confirmArchive', [$beta->id])->assertNotFound();
    callPageAction($snapshot, 'confirmArchive', [$acme->id])->assertForbidden();
    callPageAction($snapshot, 'restore', [$gamma->id])->assertNotFound();
    callPageAction($snapshot, 'confirmPurge', [$gamma->id])->assertNotFound();
    callPageAction($snapshot, 'open', [$beta->id])->assertNotFound();
    callPageAction($snapshot, 'open', [$acme->id])->assertNotFound();

    asSuperAdmin();
    onTenant($acme);
    $superAdminSnapshot = pageSnapshot(route('organisations.index'), 'pages::organisations.index');

    callPageAction($superAdminSnapshot, 'confirmArchive', [$beta->id])->assertNotFound();
    callPageAction($superAdminSnapshot, 'restore', [$gamma->id])->assertNotFound();

    expect(Organisation::query()->whereKey([$acme->id, $beta->id])->count())->toBe(2)
        ->and(Organisation::onlyTrashed()->whereKey($gamma->id)->exists())->toBeTrue()
        ->and(HandoffToken::query()->count())->toBe(0);
});

it('sends a super admin on a central host from / to the organisations index (TEN-1)', function (string $host): void {
    $organisation = Organisation::factory()->create();
    asSuperAdmin();

    match ($host) {
        'apex' => onApex(),
        default => onAdminHost(),
    };
    $this->get(route('home'))->assertRedirect(route('organisations.index'));

    onTenant($organisation);
    $this->get(route('home'))->assertRedirect(route('dashboard'));
})->with(['apex', 'admin']);
