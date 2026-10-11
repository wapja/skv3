<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\File;

it('hard-codes no kit or site name in app-identity values', function (): void {
    $allowed = [
        '/skv:[a-z-]+/',            // artisan command namespace
        "/config\\(\\[?['\"]skv\\./",    // config('skv.…') keys, read or set
        '/config\/skv\.php/',
        '/\bSkv::/',
        '/skv-[a-z-]+/',            // storage marker / state files
        '/\.ai\/guidelines\/skv3\.md/',
    ];

    $paths = collect([base_path('.env.example'), base_path('phpunit.xml')])
        ->merge(collect(File::allFiles(config_path()))->map->getPathname())
        ->merge(collect(File::allFiles(database_path('seeders')))->map->getPathname())
        ->merge(collect(File::allFiles(base_path('tests')))->map->getPathname()
            ->reject(fn (string $p): bool => str_ends_with($p, 'NameLeakTest.php')));

    $hits = $paths->flatMap(function (string $path) use ($allowed) {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return collect($lines)
            ->map(fn (string $line): ?string => preg_replace($allowed, '', $line))
            ->filter(fn (?string $line): bool => $line !== null && stripos($line, 'skv') !== false)
            ->map(fn (string $line, int $n): string => basename($path).':'.($n + 1).': '.trim($line));
    });

    expect($hits->values()->all())->toBe([]);
});
