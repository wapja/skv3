<?php

declare(strict_types=1);

/** covers §6.5 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/**
 * The __() keys in these files that have no Dutch translation, as "path: key".
 *
 * @param  iterable<string>  $paths  absolute paths
 * @return list<string>
 */
$missingDutchKeys = function (iterable $paths): array {
    $dutch = dutchTranslationKeys();
    $missing = [];

    foreach ($paths as $path) {
        preg_match_all(
            '/(?:(?<![\w$>:])(?:__|trans_choice|trans)|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
            (string) file_get_contents($path),
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $key = $match[1] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $match[2]) : stripcslashes($match[2]);
            // Group keys (passwords.token) live in lang/nl/*.php; every other key in lang/nl.json.
            $isGroupKey = preg_match('/^[\w-]+\.[\w.-]+$/', $key) === 1;
            $known = in_array($key, $dutch, true) || ($isGroupKey && Lang::hasForLocale($key, 'nl'));

            if (! $known) {
                $missing[] = str_replace(base_path().'/', '', $path).": {$key}";
            }
        }
    }

    return $missing;
};

it('has a Dutch translation for every __() key in the app and its views (FU4-6)', function () use ($missingDutchKeys): void {
    // The sidebar is covered too: its group labels are __() literals in App\Enums\NavigationGroup, and its item
    // labels are checked by NavigationTest.
    $paths = array_map(base_path(...), kitSourceFiles('app', 'resources/views'));

    expect($missingDutchKeys($paths))->toBe([]);
});

it('has a Dutch translation for every __() key in the free Flux components', function () use ($missingDutchKeys): void {
    // Flux's own copy ("Toggle sidebar", "Close modal") goes through __() too and renders on Dutch pages.
    $files = File::allFiles(base_path('vendor/livewire/flux/stubs/resources/views/flux'));
    $paths = array_map(fn (SplFileInfo $file): string => $file->getPathname(), $files);

    expect($paths)->not->toBe([])
        ->and($missingDutchKeys($paths))->toBe([]);
});
