<?php

declare(strict_types=1);

/** covers §4.8, §7.2 */

use Illuminate\Support\Str;

it('writes audit entries only through RecordActivity', function (): void {
    $files = array_values(array_filter(
        kitSourceFiles('app', 'resources/views', 'routes'),
        fn (string $path): bool => ! Str::startsWith($path, 'app/Actions/Audit/'),
    ));

    // The activity() helper, the vendor facade and the vendor logger all write entries.
    $pattern = '/(?<![\w$>:\\\\])(?<!function )activity\s*\(|Spatie\\\\Activitylog\\\\Facades\\\\Activity\b|\bActivityLogger\b/';

    expect(filesMatching($files, $pattern))->toBe([]);
});

it('uses the vendor LogsActivity trait nowhere (RL-6)', function (): void {
    // User field changes are recorded by App\Observers\UserActivityObserver; everything else by explicit events.
    expect(filesMatching(kitSourceFiles('app', 'database', 'resources/views', 'routes'), '/\bLogsActivity\b/'))->toBe([]);
});
