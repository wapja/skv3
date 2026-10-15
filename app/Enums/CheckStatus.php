<?php

declare(strict_types=1);

namespace App\Enums;

/** The outcome of one doctor check (§5.4, RL-11): green means no Fail. */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pass => __('passed'),
            self::Warn => __('warning'),
            self::Fail => __('failed'),
            self::Skipped => __('skipped'),
        };
    }

    /** The Flux badge colour (§6.5), like UserStatus::color(). */
    public function color(): string
    {
        return match ($this) {
            self::Pass => 'lime',
            self::Warn => 'amber',
            self::Fail => 'red',
            self::Skipped => 'zinc',
        };
    }
}
