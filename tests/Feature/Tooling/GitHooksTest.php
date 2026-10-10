<?php

declare(strict_types=1);

it('ships an executable pre-commit hook that checks staged PHP files', function (): void {
    $hook = base_path('.githooks/pre-commit');

    expect(is_file($hook))->toBeTrue()
        ->and(is_executable($hook))->toBeTrue()
        ->and(file_get_contents($hook))->toContain('vendor/bin/pint --test')
        ->and(file_get_contents($hook))->toContain('vendor/bin/phpstan analyse');
});
