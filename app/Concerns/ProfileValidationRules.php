<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Rules\AvailableEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Stringable;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null, ?int $organisationId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId, $organisationId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|Stringable|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails: lower case and available (W9). Pass the
     * organisation the address will be used in, so conflicts there get a specific message.
     *
     * @return array<int, ValidationRule|Stringable|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null, ?int $organisationId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'lowercase',
            'max:255',
            new AvailableEmail($organisationId, $userId),
        ];
    }
}
