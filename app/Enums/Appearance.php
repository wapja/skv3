<?php

declare(strict_types=1);

namespace App\Enums;

/** Light, dark or the system preference, stored on the user so it is the same on every subdomain (DEC-12). */
enum Appearance: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Light => __('Light'),
            self::Dark => __('Dark'),
            self::System => __('System'),
        };
    }
}
