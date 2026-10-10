<?php

declare(strict_types=1);

it('never rotates an existing APP_KEY during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $bareKeyGenerate = array_filter(
        $composer['scripts']['setup'],
        fn (string $step): bool => str_starts_with($step, '@php artisan key:generate'),
    );

    expect($bareKeyGenerate)->toBeEmpty()
        ->and(implode("\n", $composer['scripts']['setup']))->toContain('APP_KEY=');
});

it('generates the Boost guidelines during setup', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['setup'])->toContain('@php artisan boost:update --ansi');
});
