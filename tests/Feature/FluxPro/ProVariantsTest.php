<?php

declare(strict_types=1);

/** covers §6.7 */

use App\Enums\Locale;
use App\Models\Organisation;
use Composer\InstalledVersions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

it('renders the Pro variants when Flux Pro is installed', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($organisation);

    $this->get(route('users.index'))->assertOk()->assertSee('data-flux-tabs', false);
    $this->get(route('users.invite'))->assertOk()->assertSee('listbox', false);
    $this->get(route('invitations.bulk'))->assertOk()->assertSee('data-flux-file-upload', false)->assertSee('listbox', false);
    $this->get(route('activity.index'))->assertOk()->assertSee('data-flux-date-picker', false);
    $this->get(route('dashboard'))->assertOk()->assertSee('data-test="login-trend"', false)->assertSee('data-test="command-palette"', false);
    $this->get(route('profile.edit'))->assertOk()->assertSee('data-flux-file-upload', false)->assertDontSee('data-flux-input-file', false);
})->group('flux-pro')->skip(fn (): bool => ! InstalledVersions::isInstalled('livewire/flux-pro'), 'Flux Pro is not installed (OD-1)');

it('keeps the role assignment working with the listbox', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($organisation);
    $role = RoleModel::query()->create(['name' => 'Helpdesk', 'guard_name' => 'web', 'team_id' => $organisation->id]);

    Livewire::test('pages::users.invite')
        ->set('email', 'new@example.com')
        ->set('locale', Locale::English->value)
        ->set('roles', [(string) $role->id])
        ->call('invite')
        ->assertHasNoErrors();
})->group('flux-pro')->skip(fn (): bool => ! InstalledVersions::isInstalled('livewire/flux-pro'), 'Flux Pro is not installed (OD-1)');
