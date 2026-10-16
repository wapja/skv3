<?php

declare(strict_types=1);

/** covers §7.2 */
arch('app code reads configuration, never the environment')
    ->expect(['App', 'Database'])
    ->not->toUse(['env']);

arch('no debugging leftovers')
    ->expect(['App', 'Database', 'Tests'])
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump']);

arch('strict types everywhere in app code')
    ->expect('App')
    ->toUseStrictTypes();

it('has no debugging leftovers in tests and migrations', function (): void {
    $files = array_values(array_diff(kitSourceFiles('tests', 'database/migrations'), ['tests/Arch/KitRulesTest.php']));

    expect(filesMatching($files, '/(?<![\w$>:\\\\])(dd|dump|ray|var_dump)\s*\(|->(dd|dump)\s*\(/'))->toBe([]);
});

it('formats sizes without ext-intl: no Illuminate\Support\Number in app code or views (CD-9, F20)', function (): void {
    expect(filesMatching(kitSourceFiles('app', 'resources/views'), '/Illuminate\\\\Support\\\\Number\b|(?<![\w\\\\$>:])Number::/'))->toBe([]);
});
