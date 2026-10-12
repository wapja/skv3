<?php

declare(strict_types=1);

/** covers §6.5, §6.8 */

use App\Authorization\Permission;
use App\Enums\NavigationGroup;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavigationItem;

it('renders exactly the items each role may use, on every module setting', function (string $actor, bool $impersonation, bool $passkeys): void {
    // The routes each actor sees in the sidebar (RL-6); a closure, so no new global helper.
    $expected = fn (string $actor): array => match ($actor) {
        'member' => ['dashboard'],
        default => ['dashboard', 'users.index', 'invitations.pending', 'organisations.index', 'roles.index', 'activity.index'],
    };

    useImpersonationModule($impersonation);
    usePasskeysModule($passkeys);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    match ($actor) {
        'member' => asMember($organisation),
        'organisation admin' => asOrgAdmin($organisation),
        default => asSuperAdmin(),
    };

    if ($actor === 'super admin') {
        onAdminHost();
    }

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
    preg_match_all('/data-test="nav-([a-z.]+)"/', $html, $matches);

    expect(array_values(array_unique($matches[1])))->toBe($expected($actor));
})->with(['member', 'organisation admin', 'super admin'])
    ->with(['impersonation on' => true, 'impersonation off' => false])
    ->with(['passkeys on' => true, 'passkeys off' => false]);

it('shows group headings only for groups with visible items', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('dashboard'))->assertOk()
        ->assertSee(__('Overview'))
        ->assertDontSee(__('Management'))
        ->assertDontSee('>'.__('System').'<', false);
});

it('marks the current page', function (): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));

    $html = (string) $this->get(route('users.index'))->assertOk()->getContent();

    expect(preg_match('/<a[^>]*data-current[^>]*data-test="nav-users.index"|<a[^>]*data-test="nav-users.index"[^>]*data-current/', $html))->toBe(1);
});

it('filters on permission, module and host (RL-6)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $items = [
        new NavigationItem('dashboard', 'Dashboard', 'home', null, 'dashboard', NavigationGroup::Overview),
        new NavigationItem('users.index', 'Users', 'users', Permission::UsersView, 'users.*', NavigationGroup::Management),
        new NavigationItem('roles.index', 'Roles', 'shield-check', null, 'roles.*', NavigationGroup::Management, module: 'impersonation'),
        new NavigationItem('organisations.index', 'Organisations', 'building-office', null, 'organisations.*', NavigationGroup::Management, centralOnly: true),
    ];

    config(['skv.modules.impersonation' => false]);
    expect(array_map(fn (NavigationItem $item): string => $item->route, Navigation::visible($items, $member)))->toBe(['dashboard']);

    config(['skv.modules.impersonation' => true]);
    expect(array_map(fn (NavigationItem $item): string => $item->route, Navigation::visible($items, $member)))->toBe(['dashboard', 'roles.index']);

    onAdminHost();
    $admin = asSuperAdmin();
    expect(array_map(fn (NavigationItem $item): string => $item->route, Navigation::visible($items, $admin)))
        ->toBe(['dashboard', 'users.index', 'roles.index', 'organisations.index']);
});

it('groups the kit\'s items as Overzicht, Beheer and Systeem', function (): void {
    $groups = [];

    foreach (Navigation::items() as $item) {
        $groups[$item->group->value][] = $item->route;
    }

    expect($groups)->toBe([
        'overview' => ['dashboard'],
        'management' => ['users.index', 'invitations.pending', 'organisations.index', 'roles.index'],
        'system' => ['activity.index'],
    ]);
});

it('translates the group names', function (): void {
    app()->setLocale('nl');

    expect(NavigationGroup::Overview->label())->toBe('Overzicht')
        ->and(NavigationGroup::Management->label())->toBe('Beheer')
        ->and(NavigationGroup::System->label())->toBe('Systeem');
});

it('has a Dutch translation for every navigation label (FU4-6)', function (): void {
    // Item labels are constructor strings translated through __($this->label), which LangCoverageTest's literal scan cannot see.
    $dutch = dutchTranslationKeys();

    foreach (Navigation::items() as $item) {
        expect($dutch)->toContain($item->label);
    }
});
