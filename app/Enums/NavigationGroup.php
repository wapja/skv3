<?php

declare(strict_types=1);

namespace App\Enums;

/** The sidebar's groups, in display order (§6.5). */
enum NavigationGroup: string
{
    case Overview = 'overview';
    case Management = 'management';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Overview => __('Overview'),
            self::Management => __('Management'),
            self::System => __('System'),
        };
    }
}
