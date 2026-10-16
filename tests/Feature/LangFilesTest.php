<?php

declare(strict_types=1);

/** covers §6.5 */
it('keeps the Dutch and English JSON translations in step', function (): void {
    /** @var array<string, string> $dutch */
    $dutch = json_decode((string) file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, string> $english */
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($dutch))->toBe(array_keys($english));
});

it('keeps the Dutch glossary: installatie for setup, and Diagnose apart from Controle (CD-12)', function (): void {
    /** @var array<string, string> $dutch */
    $dutch = json_decode((string) file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
    $setup = array_keys(array_filter($dutch, fn (string $value): bool => stripos((string) preg_replace('#[a-z]+:[a-z-]+|/welcome-setup|composer setup#', '', $value), 'setup') !== false));

    expect($setup)->toBe([])
        ->and($dutch['Doctor'])->toBe('Diagnose')
        ->and($dutch['Check'])->toBe('Controle');
});
