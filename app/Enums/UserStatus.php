<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PendingActivation => __('Pending activation'),
            self::Active => __('Active'),
            self::Disabled => __('Disabled'),
        };
    }

    /** The Flux badge colour of the status (§6.5). */
    public function color(): string
    {
        return match ($this) {
            self::PendingActivation => 'amber',
            self::Active => 'lime',
            self::Disabled => 'zinc',
        };
    }
}
