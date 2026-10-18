<?php

declare(strict_types=1);

/** covers §4.4.3, §4.10.3, §6.2 */

use App\Actions\Invitations\InviteUser;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

/** A team role of $organisation with exactly these permissions. */
$teamRoleWith = function (Organisation $organisation, Permission ...$permissions): RoleModel {
    $role = RoleModel::query()->create(['name' => 'custom-'.Str::lower(Str::random(6)), 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));

    return $role;
};

it('lists only the own organisation\'s users on a tenant host and hides super admins from organisation admins (DEC-26 (12))', function (): void {
    $organisation = Organisation::factory()->create();
    User::factory()->for($organisation)->create(['name' => 'Cora Colleague']);
    $promoted = User::factory()->for($organisation)->create(['name' => 'Pim Promoted']);
    app(SetSuperAdmin::class)->handle($promoted, true);
    User::factory()->for(Organisation::factory())->create(['name' => 'Sven Stranger']);
    asOrgAdmin($organisation);

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSee('Cora Colleague')
        ->assertDontSee('Pim Promoted')
        ->assertDontSee('Sven Stranger');

    asSuperAdmin();
    onTenant($organisation);

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSee('Cora Colleague')
        ->assertSee('Pim Promoted')
        ->assertDontSee('Sven Stranger');
});

it('lists every user with an organisation column and their team roles for a super admin on the admin host (USR-7, R-6)', function (): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Corp']);
    $beta = Organisation::factory()->create(['name' => 'Beta Ltd']);
    $anna = User::factory()->for($acme)->create(['name' => 'Anna Admin']);
    withPermissionsTeam($acme->id, fn (): User => $anna->assignRole(Role::OrganisationAdmin->value));
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $beta->id]);
    $bert = User::factory()->for($beta)->create(['name' => 'Bert Editor']);
    withPermissionsTeam($beta->id, fn (): User => $bert->assignRole($editor));
    $operator = User::factory()->superAdmin()->create(['name' => 'Olga Operator']);
    app(SetSuperAdmin::class)->handle($operator, true);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSeeInOrder(['Anna Admin', 'Acme Corp', __('Organisation admin')])
        ->assertSeeInOrder(['Bert Editor', 'Beta Ltd', 'editor'])
        ->assertSeeInOrder(['Olga Operator', __('Platform'), __('Super admin')]);
});

it('searches name and email case-insensitively (USR-10)', function (string $term): void {
    $organisation = Organisation::factory()->create();
    User::factory()->for($organisation)->create(['name' => 'Anna Jansen', 'email' => 'anna@acme.test']);
    User::factory()->for($organisation)->create(['name' => 'Bob Smit', 'email' => 'bob@beta.test']);
    asOrgAdmin($organisation);

    Livewire::test('pages::users.index')
        ->set('search', $term)
        ->assertSee('Anna Jansen')
        ->assertDontSee('Bob Smit');
})->with(['JANSEN', 'anna@ACME', 'Acme.Test']);

it('filters by status, role and organisation, keeps the filters in the URL and resets the page (X4)', function (): void {
    $acme = Organisation::factory()->create();
    $beta = Organisation::factory()->create();
    User::factory()->for($acme)->disabled()->create(['name' => 'Dirk Disabled']);
    $editorRole = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $acme->id]);
    $editor = User::factory()->for($acme)->create(['name' => 'Eva Editor']);
    withPermissionsTeam($acme->id, fn (): User => $editor->assignRole($editorRole));
    User::factory()->for($beta)->create(['name' => 'Bea Beta']);
    User::factory()->count(30)->for($acme)->create();
    asSuperAdmin();
    onAdminHost();

    Livewire::withQueryParams(['status' => UserStatus::Disabled->value])
        ->test('pages::users.index')
        ->assertSet('status', UserStatus::Disabled->value)
        ->assertSee('Dirk Disabled')
        ->assertDontSee('Eva Editor');

    Livewire::withQueryParams(['organisation' => (string) $acme->id, 'role' => (string) $editorRole->id])
        ->test('pages::users.index')
        ->assertSee('Eva Editor')
        ->assertDontSee('Dirk Disabled');

    Livewire::withQueryParams(['organisation' => (string) $beta->id])
        ->test('pages::users.index')
        ->assertSee('Bea Beta')
        ->assertDontSee('Eva Editor');

    Livewire::test('pages::users.index')
        ->call('nextPage')
        ->assertSet('paginators.page', 2)
        ->set('search', 'e')
        ->assertSet('paginators.page', 1)
        ->call('nextPage')
        ->set('status', UserStatus::Active->value)
        ->assertSet('paginators.page', 1);
});

it('paginates by 25', function (): void {
    $organisation = Organisation::factory()->create();
    User::factory()->count(25)->for($organisation)->create();
    asOrgAdmin($organisation);

    $component = Livewire::test('pages::users.index');
    expect(substr_count($component->html(), 'data-test="user-row"'))->toBe(25);

    $component->call('nextPage');
    expect(substr_count($component->html(), 'data-test="user-row"'))->toBe(1);
});

it('answers 200 for a hostile query string', function (): void {
    asOrgAdmin(Organisation::factory()->create());

    $this->get(route('users.index').'?status[]=x&search[]=y&role=1%20or%201&organisation[]=2&view=nope&page=-3')
        ->assertOk();
});

it('shows no other organisation\'s name or role on a tenant host and ignores ?organisation= (RK-I4)', function (): void {
    $acme = Organisation::factory()->create(['name' => 'Acme Corp']);
    $beta = Organisation::factory()->create(['name' => 'Beta Secret Ltd']);
    $betaRole = RoleModel::query()->create(['name' => 'beta-secret-role', 'guard_name' => 'web', 'team_id' => $beta->id]);
    User::factory()->for($beta)->create(['name' => 'Bea Beta']);
    User::factory()->for($acme)->create(['name' => 'Cora Colleague']);
    asOrgAdmin($acme);

    $this->get(route('users.index', ['organisation' => $beta->id, 'role' => $betaRole->id]))
        ->assertOk()
        ->assertSee('Cora Colleague')
        ->assertDontSee('Bea Beta')
        ->assertDontSee('Beta Secret Ltd')
        ->assertDontSee('beta-secret-role');
});

it('deletes a member after the confirmation modal', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mila Member']);
    asOrgAdmin($organisation);

    Livewire::test('pages::users.index')
        ->call('confirmDelete', $member->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingUserId', $member->id)
        ->assertSee(__(':name can no longer sign in. You can restore the account from the archived view.', ['name' => 'Mila Member']))
        ->call('delete')
        ->assertHasNoErrors()
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingUserId', null)
        ->assertDispatched('toast-show');

    expect(User::query()->withTrashed()->find($member->id)?->trashed())->toBeTrue();
});

it('renders no delete control on your own row and refuses a forged delete of yourself (W2)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSee("confirmDelete({$member->id})", false)
        ->assertDontSee("confirmDelete({$admin->id})", false);

    Livewire::test('pages::users.index')
        ->call('confirmDelete', $admin->id)
        ->assertForbidden();

    expect($admin->fresh()?->trashed())->toBeFalse();
});

it('refuses a forged delete or restore of another organisation\'s user from a tenant host (CV-5)', function (): void {
    $acme = Organisation::factory()->create();
    $beta = Organisation::factory()->create();
    $stranger = User::factory()->for($beta)->create();
    $formerStranger = User::factory()->for($beta)->create();
    $formerStranger->delete();
    asOrgAdmin($acme);
    $snapshot = pageSnapshot(route('users.index'), 'pages::users.index');

    callPageAction($snapshot, 'confirmDelete', [$stranger->id])->assertNotFound();
    callPageAction($snapshot, 'restore', [$formerStranger->id])->assertNotFound();

    expect(DB::table('users')->where('id', $stranger->id)->value('deleted_at'))->toBeNull()
        ->and(DB::table('users')->where('id', $formerStranger->id)->value('deleted_at'))->not->toBeNull();
});

it('hides controls for users whose permissions the actor lacks (RL-26)', function () use ($teamRoleWith): void {
    $organisation = Organisation::factory()->create();
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $member = User::factory()->for($organisation)->create();
    $helpdesk = $teamRoleWith($organisation, Permission::UsersView, Permission::UsersDelete);
    $actor = asMember($organisation);
    withPermissionsTeam($organisation->id, fn (): User => $actor->assignRole($helpdesk));
    $actor->unsetRelation('roles');

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSee("confirmDelete({$member->id})", false)
        ->assertDontSee("confirmDelete({$admin->id})", false);

    Livewire::test('pages::users.index')
        ->call('confirmDelete', $admin->id)
        ->assertForbidden();
});

it('renders no delete control for a pending user (CV-20)', function (): void {
    $organisation = Organisation::factory()->create();
    $pending = User::factory()->for($organisation)->pending()->create(['name' => 'Piet Pending']);
    asOrgAdmin($organisation);

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSee('Piet Pending')
        ->assertDontSee("confirmDelete({$pending->id})", false);
});

it('renders a page of users without lazy-loading violations (RK-I6)', function (string $actor): void {
    $organisation = Organisation::factory()->create();
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $addUsers = function (int $count) use ($organisation, $editor): void {
        foreach (User::factory()->count($count)->for($organisation)->create() as $user) {
            withPermissionsTeam($organisation->id, fn (): User => $user->assignRole($editor));
        }
    };
    $queriesForOneRender = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('pages::users.index')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $addUsers(3);

    if ($actor === 'super admin') {
        asSuperAdmin();
        onAdminHost();
    } else {
        asOrgAdmin($organisation);
    }

    Livewire::test('pages::users.index')->assertOk(); // warms the permission cache
    $fewUsers = $queriesForOneRender();
    $addUsers(10);

    expect($queriesForOneRender())->toBe($fewUsers);
})->with(['organisation admin', 'super admin']);

it('lists archived users with restore and leaves out cancelled invitations\' users (C-9)', function (): void {
    $organisation = Organisation::factory()->create();
    $former = User::factory()->for($organisation)->create(['name' => 'Fien Former']);
    $former->delete();
    $cancelled = User::factory()->for($organisation)->pending()->create(['name' => 'Cees Cancelled']);
    $cancelled->delete();
    asOrgAdmin($organisation);

    Livewire::withQueryParams(['view' => 'archived'])
        ->test('pages::users.index')
        ->assertSee('Fien Former')
        ->assertDontSee('Cees Cancelled')
        ->assertSeeHtml("restore({$former->id})")
        ->call('restore', $former->id)
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect($former->fresh()?->trashed())->toBeFalse();
});

it('answers 403 for a member without users.view', function (): void {
    asMember(Organisation::factory()->create());

    $this->get(route('users.index'))->assertForbidden();
});

it('links a pending user\'s row to the pending invitations (CV-20)', function (): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);
    $templateId = (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    $invitation = app(InviteUser::class)->handle($admin, $organisation, 'invitee@example.com', [$templateId], Locale::English);

    $this->get(route('users.index'))
        ->assertOk()
        ->assertSee('data-test="pending-user-link-'.$invitation->user_id.'"', false);
});
