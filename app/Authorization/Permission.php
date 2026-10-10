<?php

declare(strict_types=1);

namespace App\Authorization;

/** The permission catalogue (§4.6). The only place where permission names are spelled out. */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case UsersInviteResend = 'users.invite.resend';
    case UsersImpersonate = 'users.impersonate';
    case RolesView = 'roles.view';
    case RolesAssign = 'roles.assign';
    case RolesManage = 'roles.manage';
    case OrganisationsView = 'organisations.view';
    case OrganisationsCreate = 'organisations.create';
    case OrganisationsUpdate = 'organisations.update';
    case OrganisationsDelete = 'organisations.delete';
    case ActivityLogView = 'activity_log.view';
    case SystemHealth = 'system.health';

    /** @return list<self> */
    public static function organisationAdmin(): array
    {
        return array_values(array_filter(self::cases(), fn (self $permission): bool => ! $permission->superOnly()));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::UsersView => __('View users'),
            self::UsersCreate => __('Invite users'),
            self::UsersUpdate => __('Edit users'),
            self::UsersDelete => __('Delete users'),
            self::UsersInviteResend => __('Resend invitations'),
            self::UsersImpersonate => __('Impersonate users'),
            self::RolesView => __('View roles'),
            self::RolesAssign => __('Assign roles'),
            self::RolesManage => __('Manage roles'),
            self::OrganisationsView => __('View organisations'),
            self::OrganisationsCreate => __('Create organisations'),
            self::OrganisationsUpdate => __('Edit organisations'),
            self::OrganisationsDelete => __('Delete organisations'),
            self::ActivityLogView => __('View activity log'),
            self::SystemHealth => __('View system health'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UsersView => __('See the organisation\'s users and their details'),
            self::UsersCreate => __('Invite new users by email'),
            self::UsersUpdate => __('Change a user\'s name, email address and status'),
            self::UsersDelete => __('Delete users and cancel pending invitations'),
            self::UsersInviteResend => __('Send a pending invitation again'),
            self::UsersImpersonate => __('Act as another user to help them'),
            self::RolesView => __('See roles and their permissions'),
            self::RolesAssign => __('Give users roles or take them away'),
            self::RolesManage => __('Create, rename and delete roles and set their permissions'),
            self::OrganisationsView => __('See organisation details'),
            self::OrganisationsCreate => __('Add new organisations'),
            self::OrganisationsUpdate => __('Change an organisation\'s name and settings'),
            self::OrganisationsDelete => __('Archive, restore and purge organisations'),
            self::ActivityLogView => __('See who did what and when'),
            self::SystemHealth => __('See health checks, backups and the scheduler'),
        };
    }

    public function group(): PermissionGroup
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDelete,
            self::UsersInviteResend, self::UsersImpersonate => PermissionGroup::Users,
            self::RolesView, self::RolesAssign, self::RolesManage => PermissionGroup::Roles,
            self::OrganisationsView, self::OrganisationsCreate, self::OrganisationsUpdate,
            self::OrganisationsDelete => PermissionGroup::Organisations,
            self::ActivityLogView => PermissionGroup::ActivityLog,
            self::SystemHealth => PermissionGroup::System,
        };
    }

    public function superOnly(): bool
    {
        return match ($this) {
            self::RolesManage, self::OrganisationsCreate, self::OrganisationsDelete, self::SystemHealth => true,
            default => false,
        };
    }
}
