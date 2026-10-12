<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/** Which language a request is answered in (§4.11, RL-8). */
final class Locales
{
    /** The signed-in user's language, else the guest's choice, else the browser's preference, else the default. */
    public static function forRequest(Request $request): Locale
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->locale;
        }

        if ($request->hasSession()) {
            $chosen = Locale::tryFrom((string) $request->session()->get('locale'));

            if ($chosen instanceof Locale) {
                return $chosen;
            }
        }

        $supported = array_map(fn (Locale $locale): string => $locale->value, Locale::cases());
        $preferred = $request->getPreferredLanguage($supported);
        $accepted = Locale::tryFrom((string) $preferred);

        // getPreferredLanguage() falls back to the first supported value when nothing matches.
        return $accepted instanceof Locale && self::browserAsksFor($request, $accepted) ? $accepted : Locale::default();
    }

    public static function apply(Locale $locale): void
    {
        app()->setLocale($locale->value);
        Date::setLocale($locale->value);
    }

    private static function browserAsksFor(Request $request, Locale $locale): bool
    {
        return array_any($request->getLanguages(), fn (string $language): bool => str_starts_with(strtolower($language), $locale->value));
    }
}
