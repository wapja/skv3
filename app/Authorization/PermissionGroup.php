<?php

declare(strict_types=1);

namespace App\Authorization;

enum PermissionGroup: string
{
    case Users = 'users';
    case Roles = 'roles';
    case Organisations = 'organisations';
    case ActivityLog = 'activity_log';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Users => __('Users'),
            self::Roles => __('Roles'),
            self::Organisations => __('Organisations'),
            self::ActivityLog => __('Activity log'),
            self::System => __('System'),
        };
    }
}
