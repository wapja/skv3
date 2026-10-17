<?php

declare(strict_types=1);

/** covers §4.3, §6.2, §6.4, §6.6 */

use App\Models\HandoffToken;
use App\Models\Organisation;
use Illuminate\Support\Facades\DB;
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
        ->assertDispatched('toast-show')
        ->assertSee('Acme Renamed');

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

it('offers the second-factor rule to organisation admins and super admins, set as stored (§6.6, RL-17, CD-3)', function (string $actor): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => true]);

    if ($actor === 'a super admin') {
        asSuperAdmin();
        onAdminHost();
    } else {
        // A second factor of their own: the rule is on, and this page is behind it.
        asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    }

    $page = route('organisations.edit', $acme->id);

    $this->get($page)
        ->assertOk()
        ->assertSee('wire:model="requireSecondFactor"', escape: false)
        ->assertSee(__('Require a second factor'));
    expect(pageSnapshot($page, 'pages::organisations.edit')['data']['requireSecondFactor'] ?? null)->toBeTrue();
})->with(['an organisation admin', 'a super admin']);

it('names a passkey in the rule\'s description only while the passkeys module is on (§6.8, CD-3)', function (bool $passkeys): void {
    usePasskeysModule($passkeys);
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    $withPasskey = __('Members must set up two-factor authentication or a passkey before they can continue. Changing this asks for your password.');
    $totpOnly = __('Members must set up two-factor authentication before they can continue. Changing this asks for your password.');

    $this->get(route('organisations.edit', $acme->id))
        ->assertOk()
        ->assertSee($passkeys ? $withPasskey : $totpOnly)
        ->assertDontSee($passkeys ? $totpOnly : $withPasskey);
})->with(['passkeys on' => true, 'passkeys off' => false]);

it('asks for a fresh password confirmation before changing the second-factor rule and comes back to this page with the unsaved form (CD-3)', function (bool $before): void {
    $acme = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'description' => null, 'require_second_factor' => $before]);
    asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $page = route('organisations.edit', $acme->id);
    $snapshot = pageSnapshot($page, 'pages::organisations.edit');

    callPageAction($snapshot, 'save', updates: ['requireSecondFactor' => ! $before, 'name' => 'Acme Renamed', 'description' => 'Parked'])
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));

    expect($acme->fresh()?->only(['name', 'description', 'require_second_factor']))
        ->toBe(['name' => 'Acme', 'description' => null, 'require_second_factor' => $before]);

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($page);

    // The unsaved form comes back after the confirmation, with a notice; the switch still counts as changed.
    $restored = pageSnapshot($page, 'pages::organisations.edit');

    expect($restored['data']['requireSecondFactor'] ?? null)->toBe(! $before)
        ->and($restored['data']['shownRequireSecondFactor'] ?? null)->toBe($before)
        ->and($restored['data']['name'] ?? null)->toBe('Acme Renamed')
        ->and($restored['data']['description'] ?? null)->toBe('Parked')
        ->and($restored['data']['draftRestored'] ?? null)->toBeTrue();

    callPageAction($restored, 'save')->assertOk()->assertJsonMissingPath('components.0.effects.redirect');

    expect($acme->fresh()?->only(['name', 'description', 'require_second_factor']))
        ->toBe(['name' => 'Acme Renamed', 'description' => 'Parked', 'require_second_factor' => ! $before]);
})->with(['turning it on' => false, 'turning it off' => true]);

it('shows the parked switch and a notice on the first render after the confirmation, and only then (CD-3)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => false]);
    asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $page = route('organisations.edit', $acme->id);
    $notice = __('Your unsaved changes are back. Save again to apply them.');

    callPageAction(pageSnapshot($page, 'pages::organisations.edit'), 'save', updates: ['requireSecondFactor' => true, 'name' => 'Acme Renamed'])
        ->assertJsonPath('components.0.effects.redirect', route('password.confirm'));
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($page);

    // The form's values travel in the component's snapshot, which the page renders as an escaped attribute.
    $this->get($page)->assertOk()
        ->assertSee($notice)
        ->assertSee('&quot;name&quot;:&quot;Acme Renamed&quot;', escape: false)
        ->assertSee('&quot;requireSecondFactor&quot;:true', escape: false);
    $this->get($page)->assertOk()
        ->assertDontSee($notice)
        ->assertSee('&quot;requireSecondFactor&quot;:false', escape: false);
});

it('keeps no parked form after a save that needs no confirmation (CD-3)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => false]);
    asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $page = route('organisations.edit', $acme->id);

    callPageAction(pageSnapshot($page, 'pages::organisations.edit'), 'save', updates: ['requireSecondFactor' => true, 'name' => 'Acme Renamed'])
        ->assertOk()
        ->assertJsonMissingPath('components.0.effects.redirect');

    expect($acme->fresh()?->require_second_factor)->toBeTrue()
        ->and(pageSnapshot($page, 'pages::organisations.edit')['data']['draftRestored'] ?? null)->toBeFalse();
});

it('tells an organisation admin without a second factor to set one up before asking for the password (CD-3)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($acme);

    Livewire::test('pages::organisations.edit', ['organisation' => $acme->id])
        ->set('requireSecondFactor', true)
        ->call('save')
        ->assertHasErrors(['requireSecondFactor' => __('Set up a second factor for your own account first.')])
        ->assertSee(__('Set up a second factor for your own account first.'))
        ->assertNoRedirect();

    expect($acme->fresh()?->require_second_factor)->toBeFalse();
});

it('keeps a rule another admin changed meanwhile when a page loaded before only renames (CD-3, plan review K0)', function (bool $shown): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => $shown]);
    asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('organisations.edit', $acme->id), 'pages::organisations.edit');

    $acme->forceFill(['require_second_factor' => ! $shown])->save();

    callPageAction($snapshot, 'save', updates: ['name' => 'Acme Renamed'])->assertOk();

    expect($acme->fresh()?->require_second_factor)->toBe(! $shown)
        ->and($acme->fresh()?->name)->toBe('Acme Renamed');
})->with(['shown off, turned on meanwhile' => false, 'shown on, turned off meanwhile' => true]);

it('asks for no password when the switch is moved to the rule another admin stored meanwhile (CD-3, plan review K0)', function (bool $shown): void {
    $acme = Organisation::factory()->create(['slug' => 'acme', 'require_second_factor' => $shown]);
    asOrgAdmin($acme)->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $snapshot = pageSnapshot(route('organisations.edit', $acme->id), 'pages::organisations.edit');

    $acme->forceFill(['require_second_factor' => ! $shown])->save();

    // No confirmed password in the session: the stored rule already matches the switch, so nothing changes it.
    callPageAction($snapshot, 'save', updates: ['requireSecondFactor' => ! $shown, 'name' => 'Acme Renamed'])
        ->assertOk()
        ->assertJsonMissingPath('components.0.effects.redirect');

    expect($acme->fresh()?->require_second_factor)->toBe(! $shown)
        ->and($acme->fresh()?->name)->toBe('Acme Renamed');
})->with(['shown off, turned on meanwhile' => false, 'shown on, turned off meanwhile' => true]);

it('shows the archived-organisation error on the edit page (FU4-1)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    asSuperAdmin();
    onAdminHost();
    $component = Livewire::test('pages::organisations.edit', ['organisation' => $organisation->id]);

    // Archive the row the moment save() reads it: the page's own read succeeds, the action's lock sees the archive.
    $armed = true;
    Organisation::retrieved(function (Organisation $retrieved) use (&$armed): void {
        if ($armed) {
            $armed = false;
            DB::table('organisations')->where('id', $retrieved->id)->update(['deleted_at' => now()]);
        }
    });

    $component->call('save')
        ->assertHasErrors('organisation')
        ->assertSee(__('This organisation is archived.'));
});
