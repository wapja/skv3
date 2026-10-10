<?php

declare(strict_types=1);

it('keeps CI in tests.yml with matrix PHP versions only', function (): void {
    $ci = file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($ci)->toContain('php-version: ${{ matrix.php }}')
        ->and(preg_match("/php-version:\s*'\d+\.\d+'/", (string) $ci))->toBe(0)
        ->and($ci)->not->toContain('./vendor/bin/phpunit')
        ->and($ci)->toContain('postgres:18')
        ->and($ci)->toContain('composer check')
        ->and(file_exists(base_path('.github/workflows/ci.yml')))->toBeFalse();
});

it('never references Flux Pro in the committed composer files', function (): void {
    expect(file_get_contents(base_path('composer.json')))->not->toContain('flux-pro')
        ->and(file_get_contents(base_path('composer.lock')))->not->toContain('flux-pro');
});
