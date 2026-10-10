<?php

declare(strict_types=1);

/** covers §4.10 */

use App\Models\User;

it('builds initials from the first and last name', function (string $name, string $initials): void {
    expect(new User(['name' => $name])->initials())->toBe($initials);
})->with([
    'two names' => ['Ada Lovelace', 'AL'],
    'three names' => ['Ada King Lovelace', 'AL'],
    'lower case' => ['ada lovelace', 'AL'],
    'single name' => ['Ada', 'A'],
]);
