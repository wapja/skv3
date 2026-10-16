<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Enums\Appearance;
use App\Enums\Locale;
use App\Models\User;
use App\Support\Skv;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use LogicException;

/**
 * Impersonation (§4.7, §6.8): an in-house, session-level identity swap on the stock `web` session guard
 * (DEC-28, OD-1). The session keeps the impersonator's id and an HMAC of their password hash next to the
 * guard's own login key, which holds the impersonated user. Sessions are host-only, so an impersonation
 * never leaves the tenant host it started on. Start and stop go through StartImpersonation and
 * StopImpersonation; EnforceImpersonation re-checks the pair on every request.
 */
final class Impersonation
{
    /** The impersonator's id; its presence in the session is what "impersonating" means. */
    public const string SESSION_KEY = 'impersonation.impersonator_id';

    /** HMAC of the impersonator's password hash at start; a password change ends the impersonation (RK-B4). */
    public const string IMPERSONATOR_HASH_KEY = 'impersonation.impersonator_hash';

    /** The language chosen during the impersonation (G2-05): it lasts as long as the impersonation, never on the target's account. */
    public const string LOCALE_KEY = 'impersonation.locale';

    /** The appearance chosen during the impersonation (G2-05), kept like LOCALE_KEY. */
    public const string APPEARANCE_KEY = 'impersonation.appearance';

    /** The module flag (§6.8), kept for existing callers; Skv::impersonationEnabled() is the one source of truth. */
    public static function enabled(): bool
    {
        return Skv::impersonationEnabled();
    }

    /**
     * Whether this session is impersonating. Deliberately independent of the flag: a module that is turned
     * off mid-impersonation must still be able to end it (RK-I3), and the audit causer rewrite still applies.
     */
    public static function isActive(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    public static function impersonatorId(): ?int
    {
        return self::idFrom(Session::get(self::SESSION_KEY));
    }

    /** The impersonated user's id, read from the guard's login key without resolving the guard's user. */
    public static function targetId(): ?int
    {
        return self::idFrom(Session::get(self::guard()->getName()));
    }

    /** The impersonator across organisations (super admins have none); null once deleted. */
    public static function impersonator(): ?User
    {
        return self::findUser(self::impersonatorId());
    }

    /** The impersonated user, looked up like the guard would; null once deleted. */
    public static function target(): ?User
    {
        return self::findUser(self::targetId());
    }

    /** Whether this session is impersonating $user. */
    public static function isTarget(User $user): bool
    {
        return self::isActive() && self::targetId() === $user->id;
    }

    /**
     * Keeps a language or appearance chosen during the impersonation in the session (G2-05): UpdateLocale and
     * UpdateAppearance call it instead of writing the impersonated user's account.
     */
    public static function rememberPreference(Locale|Appearance $choice): void
    {
        Session::put($choice instanceof Locale ? self::LOCALE_KEY : self::APPEARANCE_KEY, $choice->value);
    }

    /** The language $user sees: while this session impersonates them, the one chosen during it, else their own (G2-05). */
    public static function localeFor(User $user): Locale
    {
        $chosen = self::isTarget($user) ? Locale::tryFrom((string) Session::get(self::LOCALE_KEY)) : null;

        return $chosen ?? $user->locale;
    }

    /** The appearance $user sees, decided like localeFor() (G2-05). */
    public static function appearanceFor(User $user): Appearance
    {
        $chosen = self::isTarget($user) ? Appearance::tryFrom((string) Session::get(self::APPEARANCE_KEY)) : null;

        return $chosen ?? $user->appearance;
    }

    public static function impersonatorPasswordUnchanged(User $impersonator): bool
    {
        $stored = Session::get(self::IMPERSONATOR_HASH_KEY);

        return is_string($stored)
            && hash_equals($stored, self::guard()->hashPasswordForCookie($impersonator->getAuthPassword()));
    }

    /**
     * Whether the target's password is the one enter() stored for AuthenticateSession. A change elsewhere
     * (logoutOtherDevices) would otherwise make AuthenticateSession sign the browser out without a logged stop.
     */
    public static function targetPasswordUnchanged(User $target): bool
    {
        $guard = self::guard();

        return hash_equals((string) Session::get('password_hash_'.$guard->name), $guard->hashPasswordForCookie($target->getAuthPassword()));
    }

    /**
     * Continue as $target in this session. The impersonator must be the signed-in user; authorisation is
     * StartImpersonation's.
     */
    public static function enter(User $impersonator, User $target): void
    {
        if (self::isActive()) {
            throw new LogicException('An impersonation is already active in this session.');
        }

        $guard = self::guard();

        Session::put(self::SESSION_KEY, $impersonator->id);
        Session::put(self::IMPERSONATOR_HASH_KEY, $guard->hashPasswordForCookie($impersonator->getAuthPassword()));

        self::signInAs($target);
    }

    /** Back to the impersonator in this session. */
    public static function leave(): User
    {
        if (! self::isActive()) {
            throw new LogicException('No impersonation is active in this session.');
        }

        $impersonator = self::impersonator() ?? throw new LogicException('The impersonator no longer exists.');

        self::clear();
        self::signInAs($impersonator);

        return $impersonator;
    }

    /** Forget the impersonation keys and the preferences chosen during it; the guard's login key is left as it is. */
    public static function clear(): void
    {
        Session::forget([self::SESSION_KEY, self::IMPERSONATOR_HASH_KEY, self::LOCALE_KEY, self::APPEARANCE_KEY]);
    }

    /**
     * Sign this browser out. logoutCurrentDevice(), never logout(): logout() would cycle the impersonated
     * user's remember token and sign them out on their own devices (RK-I1). It runs before clear(), so the
     * audit listener still sees the impersonation and records no sign-out for the target.
     */
    public static function signOut(): void
    {
        $guard = self::guard();

        self::ignoreRememberCookie();
        $guard->logoutCurrentDevice();
        self::clear();
        Cookie::queue(Cookie::forget($guard->getRecallerName()));
        Session::invalidate();
        Session::regenerateToken();
    }

    /**
     * Takes the remember-me cookie out of the current request (the browser keeps it). The guard falls back
     * to that cookie as soon as the session's user cannot be loaded; while an impersonation is undone it must
     * never sign anyone in behind the scenes (RK-I1).
     */
    public static function ignoreRememberCookie(): void
    {
        $guard = self::guard();

        $guard->getRequest()->cookies->remove($guard->getRecallerName());
    }

    /**
     * The guard's own login key, a fresh session id, the password hash AuthenticateSession compares on the
     * next request, and no inherited password confirmation (RL-20, RK-D7).
     */
    private static function signInAs(User $user): void
    {
        $guard = self::guard();

        Session::put($guard->getName(), $user->getAuthIdentifier());
        Session::migrate(true);
        $guard->setUser($user);
        Session::put('password_hash_'.$guard->name, $guard->hashPasswordForCookie($user->getAuthPassword()));
        Session::forget('auth.password_confirmed_at');
    }

    private static function findUser(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }

        $user = Auth::createUserProvider('users')?->retrieveById($id);

        return $user instanceof User ? $user : null;
    }

    private static function idFrom(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private static function guard(): SessionGuard
    {
        return Auth::guard('web');
    }
}
