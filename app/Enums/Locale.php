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

    /** The configured default when it is supported, otherwise Dutch (§4.11; RL-8: never app.locale). */
    public static function default(): self
    {
        return self::tryFrom((string) config('skv.locale.default')) ?? self::Dutch;
    }

    /** The language's name in that language, for the language switchers: a reader finds their own language (§6.5). */
    public function nativeName(): string
    {
        return match ($this) {
            self::Dutch => 'Nederlands',
            self::English => 'English',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Dutch => __('Dutch'),
            self::English => __('English'),
        };
    }
}
