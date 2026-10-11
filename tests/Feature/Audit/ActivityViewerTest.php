<?php

declare(strict_types=1);

/** covers §4.8, §6.2 */

use App\Actions\Audit\RecordActivity;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Records an entry whose properties.reason holds a marker; the viewer shows it in its reason
 * column, so tests find rows by their marker. Subject-mode events are tagged with $organisation.
 */
$record = fn (ActivityEvent $event, ?Organisation $organisation, string $marker, ?User $causer = null, ?Model $subject = null): Activity => app(RecordActivity::class)->handle(
    $event,
    $subject,
    ['reason' => $marker],
    causer: $causer,
    organisation: $organisation,
    defaultCauser: false,
);

it('shows only the organisation\'s entries on a tenant host (AUD-3)', function () use ($record): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $own, 'own-entry');
    $foreign = $record(ActivityEvent::OrganisationUpdated, $other, 'foreign-entry');
    $record(ActivityEvent::OrganisationCreated, null, 'platform-entry');
    asOrgAdmin($own);

    $this->get(route('activity.index'))
        ->assertOk()
        ->assertSee('own-entry')
        ->assertDontSee('foreign-entry')
        ->assertDontSee('platform-entry');

    callPageAction(pageSnapshot(route('activity.index'), 'pages::activity.index'), 'showDetail', [$foreign->id])
        ->assertNotFound();
});

it('shows every entry, central and archived organisations\' ones included, to a super admin on a central host (AUD-11, RL-7)', function () use ($record): void {
    $active = Organisation::factory()->create(['name' => 'Active Org']);
    $archived = Organisation::factory()->create(['name' => 'Archived Org']);
    $record(ActivityEvent::OrganisationUpdated, $active, 'active-entry');
    $record(ActivityEvent::OrganisationUpdated, $archived, 'archived-entry');
    $record(ActivityEvent::OrganisationCreated, null, 'platform-entry');
    $archived->delete(); // the cascade soft-deletes its activity rows (AUD-12)
    asSuperAdmin();
    onAdminHost();

    $this->get(route('activity.index'))
        ->assertOk()
        ->assertSee(['active-entry', 'archived-entry', 'platform-entry'])
        ->assertSee('Archived Org');
});

it('filters by date range, action, subject type, causer and organisation, in the URL, and resets the page', function () use ($record): void {
    $first = Organisation::factory()->create();
    $second = Organisation::factory()->create();
    $member = User::factory()->for($first)->create();
    $actor = User::factory()->for($second)->create();
    $role = RoleModel::query()->create(['name' => 'Support', 'guard_name' => 'web', 'team_id' => $first->id]);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    $record(ActivityEvent::UserUpdated, $first, 'october-first', $member, $member);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
    $record(ActivityEvent::RoleCreated, $first, 'october-fifth', null, $role);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));
    $record(ActivityEvent::UserUpdated, $second, 'october-ninth', $actor, $actor);
    $this->travelBack();
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::activity.index')
        ->assertSee(['october-first', 'october-fifth', 'october-ninth'])
        ->set('from', '2026-10-02')
        ->set('until', '2026-10-05')
        ->assertSee('october-fifth')
        ->assertDontSee('october-first')
        ->assertDontSee('october-ninth')
        ->set('from', '')
        ->set('until', '')
        ->set('event', ActivityEvent::RoleCreated->value)
        ->assertSee('october-fifth')
        ->assertDontSee('october-first')
        ->set('event', '')
        ->set('subject', User::class)
        ->assertSee('october-first')
        ->assertSee('october-ninth')
        ->assertDontSee('october-fifth')
        ->set('subject', '')
        ->set('causer', (string) $actor->id)
        ->assertSee('october-ninth')
        ->assertDontSee('october-first')
        ->set('causer', '')
        ->set('organisation', (string) $second->id)
        ->assertSee('october-ninth')
        ->assertDontSee('october-fifth')
        ->set('organisation', '')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('event', ActivityEvent::UserUpdated->value)
        ->assertSet('paginators.page', 1);

    Livewire::withQueryParams(['event' => ActivityEvent::RoleCreated->value, 'organisation' => (string) $first->id])
        ->test('pages::activity.index')
        ->assertSet('event', ActivityEvent::RoleCreated->value)
        ->assertSee('october-fifth')
        ->assertDontSee('october-first');
});

it('paginates by 50, newest first', function () use ($record): void {
    $organisation = Organisation::factory()->create();

    foreach (range(1, 51) as $number) {
        $this->travel(1)->minutes();
        $record(ActivityEvent::OrganisationUpdated, $organisation, sprintf('entry-%02d', $number));
    }

    asOrgAdmin($organisation);

    Livewire::test('pages::activity.index')
        ->assertSeeHtmlInOrder(['entry-51', 'entry-50', 'entry-02'])
        ->assertDontSee('entry-01')
        ->call('setPage', 2)
        ->assertSee('entry-01')
        ->assertDontSee('entry-51');
});

it('names causers across scopes, super admins and deleted users included (R-5, RK-D1)', function () use ($record): void {
    $organisation = Organisation::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Sam Super']);
    $deleted = User::factory()->for($organisation)->create(['name' => 'Dora Deleted']);
    $purged = User::factory()->for($organisation)->create(['name' => 'Pete Purged']);
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'by-super-admin', $superAdmin);
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'by-deleted-user', $deleted);
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'by-purged-user', $purged);
    $deleted->delete();
    $purged->forceDelete();
    asOrgAdmin($organisation);

    $this->get(route('activity.index'))
        ->assertOk()
        ->assertSee('Sam Super')
        ->assertSee('Dora Deleted')
        ->assertDontSee('Pete Purged')
        ->assertSee(__('Deleted user'));
});

it('renders no foreign name for a forged ?causer= on a tenant host (RK-M4)', function () use ($record): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $colleague = User::factory()->for($own)->create(['name' => 'Olga Own']);
    $foreigner = User::factory()->for($other)->create(['name' => 'Bruno Foreign']);
    $record(ActivityEvent::OrganisationUpdated, $own, 'own-entry', $colleague);
    $record(ActivityEvent::OrganisationUpdated, $other, 'foreign-entry', $foreigner);
    asOrgAdmin($own);

    $this->get(route('activity.index', ['causer' => $foreigner->id]))
        ->assertOk()
        ->assertSee('own-entry')
        ->assertSee('Olga Own')
        ->assertDontSee('Bruno Foreign')
        ->assertDontSee('foreign-entry');
});

it('ignores ?organisation= on a tenant host and shows no organisation filter (RK-I4)', function () use ($record): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create(['name' => 'Other Org']);
    $record(ActivityEvent::OrganisationUpdated, $own, 'own-entry');
    $record(ActivityEvent::OrganisationUpdated, $other, 'foreign-entry');
    asOrgAdmin($own);

    $this->get(route('activity.index', ['organisation' => $other->id]))
        ->assertOk()
        ->assertSee('own-entry')
        ->assertDontSee('foreign-entry')
        ->assertDontSee('Other Org')
        ->assertDontSee('data-test="filter-organisation"', false);

    onTenant($own); // Livewire::test() skips the tenancy middleware, so bind the host's tenant again

    Livewire::withQueryParams(['organisation' => (string) $other->id])
        ->test('pages::activity.index')
        ->assertSee('own-entry')
        ->assertDontSee('foreign-entry')
        ->assertDontSeeHtml('data-test="filter-organisation"');
});

it('shows the impersonator with "as :name" for entries made while impersonating', function (): void {
    $organisation = Organisation::factory()->create();
    $impersonator = User::factory()->for($organisation)->create(['name' => 'Ivy Impersonator']);
    $target = User::factory()->for($organisation)->create(['name' => 'Tom Target']);
    app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $target,
        ['impersonated_as' => $target->id],
        causer: $impersonator,
        organisation: $organisation,
        defaultCauser: false,
    );
    asOrgAdmin($organisation);

    Livewire::test('pages::activity.index')
        ->assertSee('Ivy Impersonator')
        ->assertSee(__('as :name', ['name' => 'Tom Target']));
});

it('shows a failed sign-in with an invalid typed value as such, without the value', function (): void {
    $organisation = Organisation::factory()->create();
    Tenant::run($organisation, function (): void {
        app(RecordActivity::class)->handle(ActivityEvent::LoginFailed, properties: ['email' => null, 'invalid_address' => true], defaultCauser: false);
        app(RecordActivity::class)->handle(ActivityEvent::LoginFailed, properties: ['email' => 'typo@example.com'], defaultCauser: false);
    });
    asOrgAdmin($organisation);

    Livewire::test('pages::activity.index')
        ->assertSee(__('Not an email address'))
        ->assertSee('typo@example.com');
});

it('shows the changes and the reason, and every detail in the flyout', function () use ($record): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $entry = app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $member,
        changes: ['old' => ['name' => 'Old Name'], 'attributes' => ['name' => 'New Name']],
        organisation: $organisation,
        defaultCauser: false,
    );
    $entry->forceFill(['ip_address' => '203.0.113.9', 'user_agent' => 'DetailAgent/1.0'])->save();
    Tenant::run($organisation, fn (): Activity => $record(ActivityEvent::ImpersonationStarted, null, 'Ticket 4711'));
    asOrgAdmin($organisation);

    expect($entry->changeSummary())->toBe([['attribute' => 'name', 'old' => 'Old Name', 'new' => 'New Name']]);

    Livewire::test('pages::activity.index')
        ->assertSee('Old Name')
        ->assertSee('New Name')
        ->assertSee('Ticket 4711')
        ->assertDontSee('203.0.113.9')
        ->assertDontSee(__('Activity details'))
        ->call('showDetail', $entry->id)
        ->assertSet('detailId', $entry->id)
        ->assertSet('detailOpen', true)
        ->assertSee(__('Activity details'))
        ->assertSee('203.0.113.9')
        ->assertSee('DetailAgent/1.0')
        ->assertSee(ActivityEvent::UserUpdated->value);
});

it('hides platform staff\'s IP address, user agent and own field changes from tenant viewers, not from central ones (RL-7)', function (): void {
    // Fails before the fix: a super admin's entries on an organisation's host or data showed their IP address and
    // user agent to that organisation's viewers. Names stay visible (RK-D1).
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $leaver = User::factory()->for($organisation)->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $superAdmin = User::factory()->superAdmin()->create();
    $located = function (Activity $activity, string $ip): Activity {
        $activity->forceFill(['ip_address' => $ip, 'user_agent' => 'Agent/'.$ip])->save();

        return $activity;
    };
    $staffSignIn = $located(Tenant::run($organisation, fn (): Activity => app(RecordActivity::class)->handle(
        ActivityEvent::Login,
        $superAdmin,
        causer: $superAdmin,
        defaultCauser: false,
    )), '198.51.100.11');
    $staffEdit = $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $member,
        changes: ['old' => ['name' => 'Old Member Name'], 'attributes' => ['name' => 'New Member Name']],
        causer: $superAdmin,
        organisation: $organisation,
        defaultCauser: false,
    ), '198.51.100.22');
    $staffChange = $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $promoted,
        changes: ['old' => ['email' => 'promoted-old@example.com'], 'attributes' => ['email' => 'promoted-new@example.com']],
        organisation: $organisation,
        defaultCauser: false,
    ), '198.51.100.33');
    $memberEntry = $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $leaver,
        causer: $leaver,
        organisation: $organisation,
        defaultCauser: false,
    ), '203.0.113.44');
    $leaver->delete();
    asOrgAdmin($organisation);

    $component = Livewire::test('pages::activity.index')
        ->assertSee('New Member Name')
        ->assertDontSee('promoted-new@example.com');

    $component->call('showDetail', $staffSignIn->id)->assertDontSee('198.51.100.11')->assertDontSee('Agent/198.51.100.11');
    $component->call('showDetail', $staffEdit->id)->assertDontSee('198.51.100.22')->assertSee('New Member Name');
    $component->call('showDetail', $staffChange->id)->assertDontSee('198.51.100.33')->assertDontSee('promoted-old@example.com');
    $component->call('showDetail', $memberEntry->id)->assertSee('203.0.113.44')->assertSee('Agent/203.0.113.44');

    asSuperAdmin();
    onAdminHost();

    $central = Livewire::test('pages::activity.index')->assertSee('promoted-new@example.com');
    $central->call('showDetail', $staffSignIn->id)->assertSee('198.51.100.11')->assertSee('Agent/198.51.100.11');
    $central->call('showDetail', $staffChange->id)->assertSee('198.51.100.33')->assertSee('promoted-old@example.com');
});

it('answers 200 for a hostile query string', function (string $host) use ($record): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'some-entry');

    if ($host === 'tenant') {
        asOrgAdmin($organisation);
    } else {
        asSuperAdmin();
        onAdminHost();
    }

    $this->get(route('activity.index').'?from[]=x&until=2026-13-45&event[]=y&subject=Nope%5CModel&causer=abc&organisation[]=1')
        ->assertOk()
        ->assertSee('some-entry');
})->with(['tenant', 'admin']);
