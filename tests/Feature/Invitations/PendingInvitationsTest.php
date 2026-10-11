<?php

declare(strict_types=1);

/** covers §4.5, §6.2 */

use App\Actions\Invitations\InviteUser;
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

/** An organisation admin who is not signed in (asOrgAdmin() would sign in and switch hosts). */
$adminOf = function (Organisation $organisation, ?string $name = null): User {
    $admin = User::factory()->for($organisation)->create($name === null ? [] : ['name' => $name]);
    withPermissionsTeam($organisation->id, fn () => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');

    return $admin;
};

/** Invites with the organisation_admin template, bound to the organisation like a request on its host. */
$invite = fn (User $inviter, Organisation $organisation, string $email): Invitation => Tenant::run(
    $organisation,
    fn (): Invitation => app(InviteUser::class)->handle(
        $inviter,
        $organisation,
        $email,
        [(int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id')],
        Locale::English,
    ),
);

it('lists the own organisation\'s open invitations with inviter and relative expiry', function () use ($adminOf, $invite): void {
    $this->freezeTime();
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $ada = $adminOf($acme, 'Ada Admin');
    $open = $invite($ada, $acme, 'open@example.com');
    $accepted = $invite($ada, $acme, 'accepted@example.com');
    DB::table('invitations')->where('id', $accepted->id)->update(['accepted_at' => now()]);
    $invite($adminOf($beta), $beta, 'beta-invitee@example.com');
    asOrgAdmin($acme);

    $this->get(route('invitations.pending'))
        ->assertOk()
        ->assertSee('open@example.com')
        ->assertSee('Ada Admin')
        ->assertSee($open->expires_at->diffForHumans())
        ->assertDontSee('accepted@example.com')
        ->assertDontSee('beta-invitee@example.com');
});

it('keeps working when the inviter was deleted (W4)', function () use ($adminOf, $invite): void {
    $organisation = Organisation::factory()->create();
    $ada = $adminOf($organisation, 'Ada Admin');
    $bob = $adminOf($organisation, 'Bob Admin');
    $invite($ada, $organisation, 'from-ada@example.com');
    $invite($bob, $organisation, 'from-bob@example.com');
    // Ada is soft-deleted; Bob's row is removed for good, so invited_by becomes null (nullOnDelete).
    DB::table('users')->where('id', $ada->id)->update(['deleted_at' => now()]);
    DB::table('model_has_roles')->where('model_id', $bob->id)->delete();
    DB::table('users')->where('id', $bob->id)->delete();
    asOrgAdmin($organisation);

    $this->get(route('invitations.pending'))
        ->assertOk()
        ->assertSee('from-ada@example.com')
        ->assertSee('Ada Admin')
        ->assertSee('from-bob@example.com')
        ->assertSee(__('Deleted user'))
        ->assertDontSee('Bob Admin');
});

it('leaves out a hand-set pending user without an invitation (W4)', function (): void {
    $organisation = Organisation::factory()->create();
    User::factory()->for($organisation)->pending()->create(['email' => 'hand-set@example.com']);
    asOrgAdmin($organisation);

    $this->get(route('invitations.pending'))
        ->assertOk()
        ->assertDontSee('hand-set@example.com')
        ->assertSee(__('No open invitations.'));
});

it('reminds and cancels from the list', function () use ($invite): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);
    $first = $invite($admin, $organisation, 'first@example.com');
    $second = $invite($admin, $organisation, 'second@example.com');

    Livewire::test('pages::invitations.pending')
        ->call('remind', $first->id)
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->call('confirmCancel', $second->id)
        ->assertSet('cancellingInvitationId', $second->id)
        ->assertSet('showCancelModal', true)
        ->call('cancel')
        ->assertSet('showCancelModal', false)
        ->assertSet('cancellingInvitationId', null)
        ->assertDontSee('second@example.com');

    expect($first->fresh()?->reminder_sent_at)->not->toBeNull()
        ->and(Invitation::withTrashed()->find($second->id))->toBeNull()
        ->and(User::withTrashed()->find($second->user_id)?->trashed())->toBeTrue();
});

it('shows the invite link only to users who could grant its roles and refuses a forged showLink (INV-16, RK-B3)', function () use ($adminOf, $invite): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = $invite($adminOf($organisation), $organisation, 'new-admin@example.com');
    // May see the list, remind and assign roles, but lacks most organisation-admin permissions.
    $helpdesk = User::factory()->for($organisation)->create();
    $role = RoleModel::query()->create(['name' => 'helpdesk', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo([Permission::UsersView->value, Permission::UsersInviteResend->value, Permission::RolesAssign->value]);
    withPermissionsTeam($organisation->id, fn () => $helpdesk->assignRole($role));
    $helpdesk->unsetRelation('roles');
    $this->actingAs($helpdesk);
    onTenant($organisation);

    $this->get(route('invitations.pending'))
        ->assertOk()
        ->assertSee('new-admin@example.com')
        ->assertDontSee('data-test="show-link-'.$invitation->id.'"', false);
    $snapshot = pageSnapshot(route('invitations.pending'), 'pages::invitations.pending');
    callPageAction($snapshot, 'showLink', [$invitation->id])->assertForbidden();

    asOrgAdmin($organisation);

    $this->get(route('invitations.pending'))
        ->assertOk()
        ->assertSee('data-test="show-link-'.$invitation->id.'"', false)
        ->assertDontSee('/invitations/accept/', false);
    Livewire::test('pages::invitations.pending')
        ->call('showLink', $invitation->id)
        ->assertSet('showLinkModal', true)
        ->assertSee($invitation->acceptUrl());
});

it('refuses a forged id of another organisation\'s invitation', function (string $method) use ($adminOf, $invite): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $foreign = $invite($adminOf($beta), $beta, 'beta-invitee@example.com');
    asOrgAdmin($acme);

    $snapshot = pageSnapshot(route('invitations.pending'), 'pages::invitations.pending');
    callPageAction($snapshot, $method, [$foreign->id])->assertNotFound();

    expect(DB::table('invitations')->where('id', $foreign->id)->exists())->toBeTrue()
        ->and(DB::table('invitations')->where('id', $foreign->id)->value('reminder_sent_at'))->toBeNull();
})->with(['remind', 'confirmCancel', 'showLink']);

it('lists every organisation\'s invitations with an organisation column for a super admin on the admin host', function () use ($adminOf, $invite): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);
    $beta = Organisation::factory()->create(['name' => 'Beta Corp', 'slug' => 'beta']);
    $invite($adminOf($acme), $acme, 'acme-invitee@example.com');
    $invite($adminOf($beta), $beta, 'beta-invitee@example.com');
    asSuperAdmin();
    onAdminHost();

    // The filter's options list both names before the table, so the order proves the column.
    $this->get(route('invitations.pending'))
        ->assertOk()
        ->assertSeeInOrder(['acme-invitee@example.com', 'Acme Corp', 'beta-invitee@example.com', 'Beta Corp']);

    $this->get(route('invitations.pending', ['organisation' => $beta->id]))
        ->assertOk()
        ->assertSee('beta-invitee@example.com')
        ->assertDontSee('acme-invitee@example.com');
});

it('ignores ?organisation= on a tenant host (RK-I4)', function () use ($adminOf, $invite): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $invite($adminOf($acme), $acme, 'acme-invitee@example.com');
    $invite($adminOf($beta), $beta, 'beta-invitee@example.com');
    asOrgAdmin($acme);

    $this->get(route('invitations.pending', ['organisation' => $beta->id]))
        ->assertOk()
        ->assertSee('acme-invitee@example.com')
        ->assertDontSee('beta-invitee@example.com');
});
