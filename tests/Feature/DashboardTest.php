<?php

declare(strict_types=1);

/** covers §4.10 */

use App\Actions\Audit\RecordActivity;
use App\Actions\Organisations\ArchiveOrganisation;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

it('shows the organisation card and the user\'s roles on a tenant host (§4.10)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme', 'name' => 'Acme Corp']);
    asOrgAdmin($organisation);

    // In order: the sidebar brand shows the name too, so only the name inside the card proves the card (§4.10).
    $this->get(route('dashboard'))->assertOk()
        ->assertSeeHtmlInOrder(['data-test="organisation-card"', 'Acme Corp', 'data-test="roles-card"'])
        ->assertSee(Role::OrganisationAdmin->label());
});

it('shows Platform and the super admin badge on central hosts', function (): void {
    asSuperAdmin();
    onAdminHost();

    $this->get(route('dashboard'))->assertOk()
        ->assertSee(__('Platform'))
        ->assertSee(Role::SuperAdmin->label());
});

it('shows the super admin badge on an organisation\'s host too (§4.10)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    asSuperAdmin();
    onTenant($organisation);

    $this->get(route('dashboard'))->assertOk()
        ->assertSeeHtmlInOrder(['data-test="roles-card"', Role::SuperAdmin->label()])
        ->assertDontSee(__('No roles assigned'));
});

it('says when a member holds no roles', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('dashboard'))->assertOk()->assertSee(__('No roles assigned'));
});

it('shows stat cards only with the matching permission, counted for this host', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $other = Organisation::factory()->create(['slug' => 'other']);
    User::factory()->for($other)->count(3)->create();
    User::factory()->for($acme)->count(2)->create();

    asMember($acme);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-test="stat-users"', false);

    asOrgAdmin($acme);
    $expected = User::query()->count(); // scoped to acme: onTenant() is bound
    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('data-test="stat-users"')
        ->and(preg_match('/data-test="stat-users".*?>\s*(\d+)\s*</s', $html, $match))->toBe(1)
        ->and((int) ($match[1] ?? -1))->toBe($expected)
        ->and($expected)->toBeLessThan(User::query()->withoutGlobalScopes()->count())
        ->and($html)->not->toContain('data-test="stat-organisations"');
});

it('counts organisations for super admins on central hosts', function (): void {
    Organisation::factory()->count(2)->create();
    asSuperAdmin();
    onAdminHost();

    $this->get(route('dashboard'))->assertOk()->assertSee('data-test="stat-organisations"', false);
});

it('lists the last ten entries of this host\'s activity for users who may read the log (§4.8)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $other = Organisation::factory()->create(['slug' => 'other']);
    $admin = asOrgAdmin($acme);

    foreach (range(1, 12) as $i) {
        app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, $admin);
    }
    Tenant::run($other, fn () => app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, User::factory()->for($other)->create()));

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="recent-activity-row"'))->toBe(10)
        // e(): Blade escapes the name, and nl_NL faker names may hold an apostrophe ("van 't Riet").
        ->and($html)->toContain(e($admin->name))
        ->and($html)->toContain(e(ActivityEvent::PasswordChanged->label()));

    asMember($acme);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-test="recent-activity"', false);
});

it('lists an archived organisation\'s entries in the recent activity of a central host, as the viewer does (RL-7, G5-03)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($acme)->create();
    Tenant::run($acme, fn () => app(RecordActivity::class)->handle(ActivityEvent::PasswordChanged, $member));
    $superAdmin = asSuperAdmin();
    onAdminHost();
    app(ArchiveOrganisation::class)->handle($superAdmin, $acme);

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    // The archive cascade soft-deleted the entry; a central host still shows it.
    expect(recordedActivity(ActivityEvent::PasswordChanged)->sole()->trashed())->toBeTrue()
        ->and($html)->toContain(e(ActivityEvent::PasswordChanged->label()));
});
