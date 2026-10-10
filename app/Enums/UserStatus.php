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
}
