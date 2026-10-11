<?php

declare(strict_types=1);

/** covers §4.5, §6.2 */

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

$makeRole = fn (Organisation $organisation, string $name): RoleModel => RoleModel::query()->create([
    'name' => $name,
    'guard_name' => 'web',
    'team_id' => $organisation->id,
]);

$templateRoleId = fn (): int => (int) RoleModel::query()
    ->whereNull('team_id')
    ->where('name', Role::OrganisationAdmin->value)
    ->value('id');

it('invites from a tenant host with that organisation\'s roles', function () use ($makeRole): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $editor = $makeRole($acme, 'acme-editor');
    $makeRole($beta, 'beta-secret-role');
    asOrgAdmin($acme);

    // The suite runs with APP_LOCALE=en (phpunit.xml), so the default is English and picking Dutch proves the
    // chosen language is passed on.
    Livewire::test('pages::users.invite')
        ->assertSet('locale', Locale::default()->value)
        ->assertSee('acme-editor')
        ->assertDontSee('beta-secret-role')
        ->set('email', 'New.Person@Example.com')
        ->set('roles', [(string) $editor->id])
        ->set('locale', Locale::Dutch->value)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertRedirect(route('invitations.pending'));

    $pending = User::query()->where('email', 'new.person@example.com')->sole();

    expect($pending->organisation_id)->toBe($acme->id)
        ->and($pending->locale)->toBe(Locale::Dutch)
        ->and(Invitation::query()->where('user_id', $pending->id)->exists())->toBeTrue()
        ->and(DB::table('model_has_roles')->where('model_id', $pending->id)->where('team_id', $acme->id)->pluck('role_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([(int) $editor->id])
        ->and(session('status'))->toBe(__('Invitation sent to :email.', ['email' => 'new.person@example.com']));
});

it('refuses an unsupported language', function () use ($makeRole): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $editor = $makeRole($acme, 'acme-editor');
    asOrgAdmin($acme);

    Livewire::test('pages::users.invite')
        ->set('email', 'x@example.com')
        ->set('roles', [(string) $editor->id])
        ->set('locale', 'xx')
        ->call('invite')
        ->assertHasErrors(['locale']);

    expect(DB::table('users')->where('email', 'x@example.com')->exists())->toBeFalse();
});

it('sends an inviter without users.view to the dashboard instead of the pending list', function () use ($makeRole): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $editor = $makeRole($acme, 'acme-editor');
    // May invite (users.create and roles.assign) but may not open the pending list (users.view).
    $inviter = User::factory()->for($acme)->create();
    $role = $makeRole($acme, 'inviter');
    $role->givePermissionTo([Permission::UsersCreate->value, Permission::RolesAssign->value]);
    withPermissionsTeam($acme->id, fn () => $inviter->assignRole($role));
    $inviter->unsetRelation('roles');
    $this->actingAs($inviter);
    onTenant($acme);

    Livewire::test('pages::users.invite')
        ->assertSeeHtml('href="'.route('dashboard').'"')
        ->assertDontSeeHtml('href="'.route('invitations.pending').'"')
        ->set('email', 'helper@example.com')
        ->set('roles', [(string) $editor->id])
        ->call('invite')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect((int) DB::table('users')->where('email', 'helper@example.com')->value('organisation_id'))->toBe($acme->id);
});

it('requires a super admin on a central host to pick an organisation (INV-17)', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);
    asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::users.invite')
        ->assertSee('Acme Corp')
        ->set('email', 'first@example.com')
        ->call('invite')
        ->assertHasErrors(['organisationId']);

    // A new pick clears the roles, so the roles are chosen after the organisation.
    $page->set('organisationId', $acme->id)
        ->set('roles', [(string) $templateRoleId()])
        ->call('invite')
        ->assertHasNoErrors()
        ->assertRedirect(route('invitations.pending'));

    expect(Tenant::run($acme, fn (): int => Invitation::query()->count()))->toBe(1);
});

it('ignores a forged organisation id on a tenant host', function () use ($templateRoleId): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    asOrgAdmin($acme);

    Livewire::test('pages::users.invite')
        ->set('organisationId', $beta->id)
        ->set('email', 'someone@example.com')
        ->set('roles', [(string) $templateRoleId()])
        ->call('invite')
        ->assertHasNoErrors();

    expect(DB::table('invitations')->where('organisation_id', $acme->id)->count())->toBe(1)
        ->and(DB::table('invitations')->where('organisation_id', $beta->id)->count())->toBe(0);
});

it('refuses a forged archived organisation id on the central page (RK-I11)', function () use ($templateRoleId): void {
    $archived = Organisation::factory()->create(['slug' => 'gone']);
    $archived->delete();
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::users.invite')
        ->set('organisationId', $archived->id)
        ->set('email', 'someone@example.com')
        ->set('roles', [(string) $templateRoleId()])
        ->call('invite')
        ->assertHasErrors(['organisation']);

    expect(DB::table('invitations')->count())->toBe(0)
        ->and(DB::table('users')->where('email', 'someone@example.com')->exists())->toBeFalse();
});

it('shows no other organisation\'s names or roles on a tenant host (RK-I4)', function () use ($makeRole): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);
    $beta = Organisation::factory()->create(['name' => 'Beta Corp', 'slug' => 'beta']);
    $makeRole($acme, 'acme-editor');
    $makeRole($beta, 'beta-secret-role');
    asOrgAdmin($acme);

    $this->get(route('users.invite'))
        ->assertOk()
        ->assertSee('acme-editor')
        ->assertDontSee('beta-secret-role')
        ->assertDontSee('Beta Corp');
});

it('answers 403 without users.create, and for a users.create holder without roles.assign (OR-M7)', function () use ($makeRole): void {
    $organisation = Organisation::factory()->create();
    asMember($organisation);

    $this->get(route('users.invite'))->assertForbidden();

    $creator = User::factory()->for($organisation)->create();
    $role = $makeRole($organisation, 'creator');
    $role->givePermissionTo([Permission::UsersView->value, Permission::UsersCreate->value]);
    withPermissionsTeam($organisation->id, fn () => $creator->assignRole($role));
    $this->actingAs($creator);

    $this->get(route('users.invite'))->assertForbidden();
});
