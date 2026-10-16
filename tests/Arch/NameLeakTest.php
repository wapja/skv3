<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\File;

/*
 * The allowed forms of the kit name, removed from a line before it is searched, in this order (the alias rule runs
 * before the Skv:: rule, which would strip half of it). A state-file name counts only whole, so `skv-installed-old` or
 * `skv-herd.log.1` still leak (g4-05).
 */
$allowed = [
    '/skv:[a-z-]+/',                     // artisan command namespace
    "/config\\(\\[?['\"]skv\\./",        // config('skv.…') keys, read or set
    "/->setting\\('[A-Z_]+', 'skv\\./",  // DoctorContext::setting()'s config key (CD-5)
    '/config\/skv\.php/',
    "/->alias\\('Skv', /",               // the global Skv alias (RL-1)
    '/\bSkv::/',
    '/App\\\\Support\\\\Skv\b/',         // the Skv support class name (RL-1)
    '/\bfinal class Skv\b|alias `Skv`/', // its declaration and its docblock
    '#skv-(?:installed|setup-token|setup\.(?:json|lock)|setup|herd\.(?:json|exit|log))(?![\w.-])#', // the setup's state files (RL-5); tests use the constants
    '/\bextra\.skv(?:\.flux-pro)?(?![\w.-])/', // skv:flux-pro's composer.json mark (CD-13)
    '/\.ai\/guidelines\/skv3\.md/',
];

/** Whether $line still names the kit once the allowed forms are removed. */
$leaks = fn (string $line): bool => stripos((string) preg_replace($allowed, '', $line), 'skv') !== false;

it('hard-codes no kit or site name in app-identity values', function () use ($leaks): void {
    $paths = collect([base_path('.env.example'), base_path('phpunit.xml')])
        ->merge(collect(File::allFiles(config_path()))->map->getPathname())
        ->merge(collect(File::allFiles(database_path('seeders')))->map->getPathname())
        ->merge(collect(File::allFiles(app_path()))->map->getPathname())
        ->merge(collect(File::allFiles(resource_path('views')))->map->getPathname())
        ->merge(collect(File::allFiles(lang_path()))->map->getPathname())
        ->merge(collect(File::allFiles(base_path('tests')))->map->getPathname()
            ->reject(fn (string $p): bool => str_ends_with($p, 'NameLeakTest.php')));

    $hits = $paths->flatMap(function (string $path) use ($leaks) {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return collect($lines)
            ->filter(fn (string $line): bool => $leaks($line))
            ->map(fn (string $line, int $n): string => basename($path).':'.($n + 1).': '.trim($line));
    });

    expect($hits->values()->all())->toBe([]);
});

it('allows the setup state-file names only whole (g4-05)', function () use ($leaks): void {
    expect($leaks("'skv-installed-old'"))->toBeTrue()
        ->and($leaks("'skv-herd.log.1'"))->toBeTrue()
        ->and($leaks("'skv-setup.json.bak'"))->toBeTrue()
        ->and($leaks("'storage/app/private/skv-herd.log'"))->toBeFalse()
        ->and($leaks("'storage/app/private/skv-setup'"))->toBeFalse()
        ->and($leaks("'storage/app/private/skv-setup/frontend.json'"))->toBeFalse();
});

it('finds a kit name planted in app code (g2-12)', function () use ($leaks): void {
    expect($leaks("        'name' => 'skv3',"))->toBeTrue()
        ->and($leaks("config('skv.modules.backups')"))->toBeFalse()
        ->and($leaks('php artisan skv:doctor --fix'))->toBeFalse();
});
