<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Authorization\Permission;
use App\Enums\NavigationGroup;
use App\Models\User;
use App\Support\Skv;
use App\Tenancy\Facades\Tenant;

/** The single navigation definition (§6.5, RL-6). */
final class Navigation
{
    /** @return list<NavigationItem> */
    public static function items(): array
    {
        return [
            new NavigationItem('dashboard', 'Dashboard', 'home', null, 'dashboard', NavigationGroup::Overview),
            new NavigationItem('users.index', 'Users', 'users', Permission::UsersView, 'users.*', NavigationGroup::Management),
            new NavigationItem('invitations.pending', 'Pending invitations', 'envelope', Permission::UsersView, 'invitations.pending', NavigationGroup::Management),
            new NavigationItem('organisations.index', 'Organisations', 'building-office', Permission::OrganisationsView, 'organisations.*', NavigationGroup::Management),
            new NavigationItem('roles.index', 'Roles', 'shield-check', Permission::RolesView, 'roles.*', NavigationGroup::Management),
            new NavigationItem('activity.index', 'Activity log', 'clipboard-document-list', Permission::ActivityLogView, 'activity.*', NavigationGroup::System),
            new NavigationItem('system.index', 'System status', 'server-stack', Permission::SystemHealth, 'system.*', NavigationGroup::System, centralOnly: true),
        ];
    }

    /**
     * The items this user may use on this host with the current module flags.
     *
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    public static function visible(array $items, User $user): array
    {
        return array_values(array_filter($items, fn (NavigationItem $item): bool => (! $item->permission instanceof Permission || $user->can($item->permission))
            && ($item->module === null || Skv::moduleEnabled($item->module))
            && (! $item->centralOnly || ! Tenant::check())));
    }

    /** @return list<array{group: NavigationGroup, items: list<NavigationItem>}> */
    public static function for(User $user): array
    {
        $visible = self::visible(self::items(), $user);
        $groups = [];

        foreach (NavigationGroup::cases() as $group) {
            $items = array_values(array_filter($visible, fn (NavigationItem $item): bool => $item->group === $group));

            if ($items !== []) {
                $groups[] = ['group' => $group, 'items' => $items];
            }
        }

        return $groups;
    }
}
