<?php

declare(strict_types=1);

namespace App\Rules;

use App\Tenancy\Hosts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An organisation slug is one lower-case host label that the tenant resolver accepts (§4.3, RL-23): the same
 * pattern and reserved set as Hosts::slugOf(), so a valid slug always resolves to its organisation. Upper case
 * is refused rather than lowered, so the address a super admin types is the address that is stored.
 */
final class OrganisationSlug implements ValidationRule
{
    /** @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(Hosts::SLUG_PATTERN, $value) !== 1) {
            $fail(__('Use 3 to 63 lower-case letters, digits and hyphens, without a hyphen at the start or end.'));

            return;
        }

        if (Hosts::isReservedSlug($value)) {
            $fail(__('This address is reserved.'));
        }
    }
}
