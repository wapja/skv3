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
