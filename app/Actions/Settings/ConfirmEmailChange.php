<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies a confirmed email change (E1). Only the newest link works, and only once: the nonce is
 * forgotten when it matches, so an older link never removes the newest one. The link is checked on the
 * locked users row against the address it was requested from, so an administrator's correction that
 * committed meanwhile (UpdateUser forgets the link only after its commit) is never overwritten.
 */
final class ConfirmEmailChange
{
    use ProfileValidationRules;

    /** @throws ValidationException */
    public function handle(User $user, string $email, string $nonce): void
    {
        $email = Str::lower($email);
        $key = RequestEmailChange::cacheKey($user);

        try {
            DB::transaction(function () use ($user, $email, $nonce, $key): void {
                $previous = DB::table('users')->where('id', $user->id)->lockForUpdate()->value('email');
                $expected = Cache::get($key);

                if (! is_string($previous) || ! is_string($expected) || ! hash_equals($expected, RequestEmailChange::digest($nonce, $previous, $email))) {
                    throw ValidationException::withMessages(['email' => [__('This confirmation link is no longer valid.')]]);
                }

                Cache::forget($key);

                Validator::make(['email' => $email], ['email' => $this->emailRules($user->id, $user->organisation_id)])->validate();

                $user->forceFill(['email' => $email])->save();

                // Reset links issued for the old address must not work any more, and reset tokens are keyed by
                // address: one issued for the new address belongs to whoever held it before.
                DB::table('password_reset_tokens')->whereIn('email', [$previous, $email])->delete();
            });
        } catch (UniqueConstraintViolationException) {
            // Another request took the address between validation and save (FU3-8).
            $user->refresh();

            throw ValidationException::withMessages(['email' => [__('This address can\'t be used.')]]);
        }
    }
}
