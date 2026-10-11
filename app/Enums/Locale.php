<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The supported locales, defined once (§6.5): validation, the migration default, the factory,
 * the invite form and, from M5, the switchers use this enum (OD-2).
 */
enum Locale: string
{
    case Dutch = 'nl';
    case English = 'en';

    /** The app locale when it is supported, otherwise Dutch (§4.11: nl is the kit default). */
    public static function default(): self
    {
        return self::tryFrom((string) config('app.locale')) ?? self::Dutch;
    }

    public function label(): string
    {
        return match ($this) {
            self::Dutch => __('Dutch'),
            self::English => __('English'),
        };
    }
}
