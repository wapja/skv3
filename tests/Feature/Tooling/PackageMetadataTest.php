<?php

declare(strict_types=1);

/** covers §10 */

use Tests\Fixtures\KitRepository;

/**
 * The patterns .gitattributes marks export-ignore: what a dist install from Packagist (a GitHub zipball, Composer's
 * default) leaves out (F50).
 *
 * @return list<string>
 */
$exportIgnored = function (): array {
    $patterns = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.gitattributes'))) ?: [] as $line) {
        $fields = preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($fields !== [] && ! str_starts_with($fields[0], '#') && in_array('export-ignore', array_slice($fields, 1), true)) {
            $patterns[] = ltrim($fields[0], '/');
        }
    }

    return $patterns;
};

/** @return array<string, mixed> */
$composer = function (): array {
    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    return $composer;
};

it('ships every file the app points its users to in a Packagist install (F50)', function () use ($exportIgnored): void {
    $patterns = $exportIgnored();
    $ignored = fn (string $file): bool => array_any($patterns, fn (string $pattern): bool => fnmatch($pattern, $file) || str_starts_with($file, rtrim($pattern, '/').'/'));
    // The README holds the production checklist BootstrapCommand sends a production install to; the MIT notice must
    // travel with every copy; the others are what the docs, the e2e job and a new app's CI read.
    $shipped = ['README.md', 'LICENSE', 'CHANGELOG.md', 'docs/handleiding.html', '.env.example', 'composer.json', 'composer.lock', '.github/workflows/tests.yml', 'tests/Fixtures/e2e-answers.json'];

    expect((string) file_get_contents(app_path('Console/Commands/BootstrapCommand.php')))->toContain('production checklist in the README')
        ->and(array_values(array_filter($shipped, fn (string $file): bool => ! is_file(base_path($file)))))->toBe([])
        ->and(array_values(array_filter($shipped, $ignored)))->toBe([]);
});

it('leaves only the kit-only files out of a Packagist install (M8b)', function () use ($exportIgnored): void {
    // The release check runs in the kit's repository alone, and the milestone plans are its build history; the spec
    // stays, because scripts/traceability.php and TraceabilityTest read it in every app.
    expect($exportIgnored())->toBe(['.github/workflows/release-check.yml', 'docs/superpowers/plans']);
});

it('ships the MIT licence composer.json declares (F59, CD-20)', function () use ($composer): void {
    $license = is_file(base_path('LICENSE')) ? (string) file_get_contents(base_path('LICENSE')) : '';

    expect($composer()['license'] ?? null)->toBe('MIT')
        ->and($license)->toStartWith("MIT License\n\nCopyright (c) ")
        ->toContain('Permission is hereby granted, free of charge, to any person obtaining a copy')
        ->toContain('The above copyright notice and this permission notice shall be included in all')
        ->toContain('THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND');
})->skip(fn (): bool => ! KitRepository::isKit(), 'An app generated from the kit chooses its own licence.');

it('describes the package for Packagist, with links built from its own name (F58)', function () use ($composer): void {
    $package = $composer();
    // Built from composer.json's name: NameLeakTest scans tests/, so this file holds no kit name literal.
    $repository = 'https://github.com/'.$package['name'];

    expect($package['type'] ?? null)->toBe('project')
        ->and($package['description'] ?? '')->not->toBe('')
        ->and($package['keywords'] ?? null)->toBe(['laravel', 'starter-kit', 'multi-tenant', 'livewire', 'flux', 'saas'])
        ->and($package['homepage'] ?? null)->toBe($repository)
        ->and($package['support'] ?? null)->toBe(['issues' => $repository.'/issues', 'source' => $repository]);
})->skip(fn (): bool => ! KitRepository::isKit(), 'An app generated from the kit describes itself.');
