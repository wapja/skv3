<?php

declare(strict_types=1);

/** covers §6.5 */

use Illuminate\Support\Facades\Lang;

it('has a Dutch translation for every __() key in the app and its views (FU4-6)', function (): void {
    // The sidebar is covered too: its group labels are __() literals in App\Enums\NavigationGroup, and its item
    // labels are checked by NavigationTest.
    $files = kitSourceFiles('app', 'resources/views');
    $dutch = dutchTranslationKeys();
    $missing = [];

    foreach ($files as $path) {
        preg_match_all(
            '/(?:(?<![\w$>:])(?:__|trans_choice|trans)|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
            (string) file_get_contents(base_path($path)),
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $key = $match[1] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $match[2]) : stripcslashes($match[2]);
            // Group keys (passwords.token) live in lang/nl/*.php; every other key in lang/nl.json.
            $isGroupKey = preg_match('/^[\w-]+\.[\w.-]+$/', $key) === 1;
            $known = in_array($key, $dutch, true) || ($isGroupKey && Lang::hasForLocale($key, 'nl'));

            if (! $known) {
                $missing[] = "{$path}: {$key}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('never translates the copy inside the Flux components Blaze folds (G3-08, DEC-29)', function (string $locale): void {
    // Blaze runs a folded component's __() once, when the view compiles, and memoises the result: a translation here
    // would show the first language to compile to everyone. The labels stay English for every locale instead.
    $folded = ['Toggle password visibility', 'Clear input', 'Copy to clipboard', 'Choose file', 'Choose files', 'No file chosen', 'Close modal', 'Character {current} of {total}'];
    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    expect(array_values(array_intersect($folded, array_keys($translations))))->toBe([]);
})->with(['nl', 'en']);
