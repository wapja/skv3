<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Locale;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

/** Which language a request is answered in (§4.11, RL-8). */
final class Locales
{
    /**
     * The signed-in user's language (during an impersonation the one chosen in it, G2-05), else the guest's choice, else
     * the browser's preference, else the default.
     */
    public static function forRequest(Request $request): Locale
    {
        return self::resolve($request, $request->user());
    }

    /**
     * The same choice for an error page, which may render before EnforceImpersonation has re-checked a running
     * impersonation (a CSRF 419, an early 500). It uses only a user the guard already resolved and never asks the
     * guard itself, so a gone target never falls back to a remember-me sign-in (RL-20, RK-I1).
     */
    public static function forErrorPage(Request $request): Locale
    {
        return self::resolve($request, self::resolvedUser());
    }

    /**
     * The language the next page for $user is answered in, or a guest's when nobody will be signed in (G3-01, G3-02):
     * for a status flashed on a redirect after a sign-out or an impersonation swap, when the request's own language
     * (applied by SetLocale, or not yet at all) belongs to someone else. Never asks the guard (RL-20, RK-I1).
     */
    public static function forNextPage(Request $request, ?User $user): Locale
    {
        return self::resolve($request, $user);
    }

    /** The web guard's user when something already resolved it, without resolving it here. */
    public static function resolvedUser(): ?User
    {
        $guard = Auth::guard('web');
        $user = $guard->hasUser() ? $guard->user() : null;

        return $user instanceof User ? $user : null;
    }

    public static function apply(Locale $locale): void
    {
        app()->setLocale($locale->value);
        Date::setLocale($locale->value);
    }

    private static function resolve(Request $request, mixed $user): Locale
    {
        if ($user instanceof User) {
            return Impersonation::localeFor($user);
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

    private static function browserAsksFor(Request $request, Locale $locale): bool
    {
        return array_any($request->getLanguages(), fn (string $language): bool => str_starts_with(strtolower($language), $locale->value));
    }
}
