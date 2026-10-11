<?php

declare(strict_types=1);

/** covers §4.3, §6.2 */

use App\Rules\OrganisationSlug;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Validator;

it('accepts lower-case slugs of 3 to 63 letters, digits and inner hyphens', function (string $slug): void {
    expect(Validator::make(['slug' => $slug], ['slug' => [new OrganisationSlug]])->passes())->toBeTrue();
})->with([
    'letters' => 'acme',
    'shortest' => 'abc',
    'digits' => '123',
    'inner hyphen' => 'acme-2',
    'longest' => str_repeat('a', 63),
]);

it('refuses slugs that are not one valid host label, or are reserved', function (string $slug, string $reason): void {
    $validator = Validator::make(['slug' => $slug], ['slug' => [new OrganisationSlug]]);

    expect($validator->errors()->first('slug'))->toBe($reason === 'reserved'
        ? __('This address is reserved.')
        : __('Use 3 to 63 lower-case letters, digits and hyphens, without a hyphen at the start or end.'));
})->with([
    'upper case' => ['Acme', 'format'],
    'underscore' => ['ac_me', 'format'],
    'leading hyphen' => ['-acme', 'format'],
    'trailing hyphen' => ['acme-', 'format'],
    'two characters' => ['ab', 'format'],
    '64 characters' => [str_repeat('a', 64), 'format'],
    'space' => ['ac me', 'format'],
    'dot' => ['acme.example', 'format'],
    'reserved www' => ['www', 'reserved'],
    'reserved admin' => ['admin', 'reserved'],
    'reserved dashboard' => ['dashboard', 'reserved'],
]);

it('refuses a value that is not a string', function (): void {
    expect(Validator::make(['slug' => ['acme']], ['slug' => [new OrganisationSlug]])->fails())->toBeTrue();
});

it('reserves the configured admin subdomain (FU2-1, FU3-5)', function (): void {
    config(['tenancy.admin_subdomain' => 'beheer']);

    $validator = Validator::make(['slug' => 'beheer'], ['slug' => [new OrganisationSlug]]);

    expect($validator->errors()->first('slug'))->toBe(__('This address is reserved.'))
        ->and(Hosts::slugOf('beheer.localhost'))->toBeNull();
});
