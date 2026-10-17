<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A new password is never a password hash (L7b-38): the `hashed` cast stores a value that already is a hash as it is,
 * so a bcrypt hash would become the account's hash and its plaintext the password, past the strength rule, and a hash
 * of another algorithm makes the cast throw. Every path that sets a password applies it next to Password::default().
 */
final class NotAPasswordHash implements ValidationRule
{
    /** @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Hash::isHashed($value)) {
            $fail(__('Pass the password itself, not a hash.'));
        }
    }
}
