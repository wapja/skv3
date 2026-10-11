<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * One address rule for inviting, the admin edit, restoring a user and the profile email change
 * (§4.4, §4.5, W9, RL-14). Deleted users never block an address. Inside the organisation being
 * edited the message names the conflict; an address held in another organisation or by a super
 * admin gets one generic message, so the rule does not tell which organisation uses it. The query
 * reads the users table directly: the check must see every organisation, whatever tenant is bound.
 */
final readonly class AvailableEmail implements ValidationRule
{
    public function __construct(public ?int $organisationId, public ?int $ignoreUserId = null) {}

    /** @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $holders = DB::table('users')
            ->where('email', Str::lower($value))
            ->whereNull('deleted_at');

        if ($this->ignoreUserId !== null) {
            $holders->where('id', '!=', $this->ignoreUserId);
        }

        $holder = $holders->first(['organisation_id', 'is_super_admin', 'status']);

        if ($holder === null) {
            return;
        }

        $sameOrganisation = $this->organisationId !== null
            && ! (bool) $holder->is_super_admin
            && $holder->organisation_id !== null
            && (int) $holder->organisation_id === $this->organisationId;

        if (! $sameOrganisation) {
            $fail(__('This address can\'t be used.'));

            return;
        }

        $fail($holder->status === UserStatus::PendingActivation->value
            ? __('This address already has an open invitation.')
            : __('This address is already used in this organisation.'));
    }
}
