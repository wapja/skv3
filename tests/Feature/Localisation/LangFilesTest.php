<?php

declare(strict_types=1);

/** covers §6.5 */

use Illuminate\Support\Arr;

it('ships Dutch versions of the framework\'s validation, pagination, auth and password lines', function (string $group): void {
    /** @var array<string, mixed> $english */
    $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$group}.php");
    /** @var array<string, mixed> $dutch */
    $dutch = require lang_path("nl/{$group}.php");

    // `attributes` and `custom` are app-specific: English ships them empty or as a placeholder, and Arr::dot keeps
    // an empty array as one leaf key, so they are left out of the comparison and checked on their own below.
    $keys = fn (array $lines): array => array_keys(Arr::dot(Arr::except($lines, ['attributes', 'custom'])));

    expect($keys($dutch))->toEqualCanonicalizing($keys($english));
})->with(['validation', 'pagination', 'auth', 'passwords']);

it('names the kit\'s form fields in Dutch validation messages', function (): void {
    /** @var array{attributes: array<string, string>} $dutch */
    $dutch = require lang_path('nl/validation.php');

    expect(array_keys($dutch['attributes']))->toEqualCanonicalizing(['email', 'password', 'name', 'current_password', 'locale']);
});

it('translates every page title (RL-9)', function (): void {
    $dutch = dutchTranslationKeys();

    foreach (kitSourceFiles('resources/views') as $path) {
        preg_match_all("/#\\[Title\\('((?:[^'\\\\]|\\\\.)*)'\\)\\]/", (string) file_get_contents(base_path($path)), $matches);

        foreach ($matches[1] as $title) {
            expect(in_array($title, $dutch, true))->toBeTrue("Missing Dutch title [{$title}] in {$path}");
        }
    }
});
