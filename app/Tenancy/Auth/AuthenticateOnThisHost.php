<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use LogicException;

/**
 * Fortify's login callback (§4.2). An unknown address, a wrong password, an inactive user and a user
 * on a host they do not belong to all fail the same way and take the same time, so the response never
 * reveals which one it was: an unknown address still costs one password hash, and every failure is
 * padded to `auth.timebox_duration`. Fortify calls the callback twice for users without 2FA, so the
 * outcome is kept on the request. A user the second-factor rule applies to whose only factor is a passkey
 * is refused once the password is right, as a TOTP user is challenged only then (§6.6, DEC-35): they sign
 * in with the passkey.
 */
final readonly class AuthenticateOnThisHost
{
    private const string RESULT = 'auth.authenticated-on-this-host';

    public function __construct(private HostLoginRule $rule) {}

    public function __invoke(Request $request): ?User
    {
        if ($request->attributes->has(self::RESULT)) {
            $cached = $request->attributes->get(self::RESULT);

            return $cached instanceof User ? $cached : null;
        }

        $user = new Timebox()->call(
            fn (Timebox $timebox): ?User => $this->attempt($request, $timebox),
            (int) config('auth.timebox_duration', 200_000),
        );
        $user = $user instanceof User ? $user : null;

        $request->attributes->set(self::RESULT, $user);

        return $user;
    }

    private function attempt(Request $request, Timebox $timebox): ?User
    {
        $provider = Auth::createUserProvider('users') ?? throw new LogicException('The users auth provider is not configured.');
        $user = $provider->retrieveByCredentials(['email' => Str::lower((string) $request->input('email'))]);
        $credentials = ['password' => (string) $request->input('password')];

        if (! $user instanceof User) {
            // One hash at the configured cost, as for a known user's password, so the time does not reveal the
            // address. The value is irrelevant; a short one never trips BCRYPT_LIMIT.
            Hash::make(Str::random(32));

            return null;
        }

        if (! $provider->validateCredentials($user, $credentials)) {
            return null;
        }

        $provider->rehashPasswordIfRequired($user, $credentials);

        if (! $this->rule->allows($user)) {
            return null;
        }

        $timebox->returnEarly();

        if (SecondFactor::needsPasskeySignIn($user)) {
            throw ValidationException::withMessages([
                Fortify::username() => __('Sign in with your passkey: a password alone is not enough for this account.'),
            ]);
        }

        return $user;
    }
}
