<?php

declare(strict_types=1);

/** covers §6.2, §7.2 */

use Illuminate\Support\Str;

it('bypasses the tenant scope only in the allowed places', function (): void {
    // §6.2. Central Livewire components are added here from M4 on, one path at a time.
    $allowed = [
        'app/Console/', 'app/Setup/', 'app/Tenancy/', 'app/Actions/Audit/', 'database/seeders/',
        'app/Http/Controllers/HealthCheckController.php',
    ];

    $candidates = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'resources/views', 'database'),
        fn (string $path): bool => ! Str::startsWith($path, $allowed),
    ));

    expect(filesMatching($candidates, '/Tenant::bypass\s*\(|->bypass\s*\(/'))->toBe([]);
});

it('keeps driver-specific SQL out of the app', function (): void {
    expect(filesMatching(kitSourceFiles('app', 'resources/views'), '/\bilike\b/i'))->toBe([]);
});
