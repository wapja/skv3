<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Organisation;
use App\Rules\OrganisationSlug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Stringable;

/**
 * Names and slugs are unique across all organisations, archived ones included: the unique indexes cover
 * soft-deleted rows, so archived names and slugs stay reserved (§4.3, C-14). Only super admins are told that
 * an archived organisation holds a value; everyone else gets the generic message (RK-M2).
 *
 * A name is shown in Markdown mails and organisation admins may change it, so it may hold no square or angle
 * brackets (link, image and autolink syntax) and no control characters such as line breaks, which would let
 * a name open new Markdown blocks.
 */
trait OrganisationValidationRules
{
    /** @return array<int, ValidationRule|Stringable|Closure|array<mixed>|string> */
    protected function organisationNameRules(?int $ignoreId = null, bool $revealArchived = false): array
    {
        return ['bail', 'required', 'string', 'max:255', $this->plainOrganisationName(), $this->uniqueOrganisationValue('name', $ignoreId, $revealArchived)];
    }

    /** @return array<int, ValidationRule|Stringable|Closure|array<mixed>|string> */
    protected function organisationSlugRules(?int $ignoreId = null, bool $revealArchived = false): array
    {
        return ['bail', 'required', 'string', new OrganisationSlug, $this->uniqueOrganisationValue('slug', $ignoreId, $revealArchived)];
    }

    /** @return array<int, string> */
    protected function organisationDescriptionRules(): array
    {
        return ['nullable', 'string', 'max:1000'];
    }

    private function plainOrganisationName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/[\[\]<>\p{Cc}]/u', $value) !== 0) {
                $fail(__('The name may not contain square brackets, angle brackets or control characters.'));
            }
        };
    }

    /** @param  'name'|'slug'  $column */
    private function uniqueOrganisationValue(string $column, ?int $ignoreId, bool $revealArchived): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($column, $ignoreId, $revealArchived): void {
            $holder = Organisation::withTrashed()
                ->where($column, $value)
                ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
                ->first(['id', 'deleted_at']);

            if ($holder === null) {
                return;
            }

            $fail(match (true) {
                ! $revealArchived => $column === 'name' ? __('This name can\'t be used.') : __('This address can\'t be used.'),
                $holder->trashed() => $column === 'name'
                    ? __('This name is used by an archived organisation.')
                    : __('This address is used by an archived organisation.'),
                default => $column === 'name'
                    ? __('This name is already used by another organisation.')
                    : __('This address is already used by another organisation.'),
            });
        };
    }
}
