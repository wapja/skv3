<?php

declare(strict_types=1);

/** covers §6.5 */

use Illuminate\Support\Facades\Lang;

it('has a Dutch translation for every __() key in the M4 paths (OR-I8)', function (): void {
    // The paths M4 writes copy in. Starter screens (settings, auth views) get their Dutch copy in M5.
    $directories = [
        'app/Actions', 'app/Authorization', 'app/Concerns', 'app/Enums', 'app/Http/Controllers', 'app/Mail',
        'app/Notifications', 'app/Rules', 'app/Tenancy', 'resources/views/pages/users', 'resources/views/pages/invitations',
        'resources/views/pages/organisations', 'resources/views/pages/roles', 'resources/views/pages/activity',
        'resources/views/mail',
    ];
    $singleFiles = [
        'resources/views/pages/auth/handoff-confirm.blade.php',
        'resources/views/components/flash-toast.blade.php',
        'resources/views/components/impersonation-banner.blade.php',
    ];

    $files = [
        ...kitSourceFiles(...$directories),
        ...array_values(array_filter($singleFiles, fn (string $path): bool => is_file(base_path($path)))),
    ];
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
