<?php

declare(strict_types=1);

/** covers §5.2 */

/**
 * The tokens of every *Test.php file, without whitespace and comments. `laravel new --pest` runs Pest Drift over
 * these files (DEC-22): it rewrites every class it finds in them and imports every qualified name.
 *
 * @return array<string, list<array{0: int, 1: string, 2: int}|string>>
 */
$testFileTokens = fn (): array => collect(kitSourceFiles('tests'))
    ->filter(fn (string $path): bool => str_ends_with($path, 'Test.php'))
    ->mapWithKeys(fn (string $path): array => [$path => array_values(array_filter(
        token_get_all((string) file_get_contents(base_path($path))),
        fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ))])
    ->all();

it('declares no class in a *Test.php file, so the installer\'s Pest Drift step leaves the tests alone (DEC-22)', function () use ($testFileTokens): void {
    $offenders = [];

    foreach ($testFileTokens() as $path => $tokens) {
        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;

            if (is_array($token) && $token[0] === T_CLASS && (! is_array($previous) || $previous[0] !== T_DOUBLE_COLON)) {
                $offenders[] = $path;

                break;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('spells no qualified class name outside the imports of a *Test.php file, so the installer\'s Pest Drift step leaves the tests alone (DEC-22)', function () use ($testFileTokens): void {
    $offenders = [];

    foreach ($testFileTokens() as $path => $tokens) {
        $inImport = false;

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;

            if (is_array($token) && $token[0] === T_USE && $previous !== ')') {
                $inImport = true;
            } elseif ($token === ';') {
                $inImport = false;
            } elseif (! $inImport && is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $offenders[] = $path.':'.$token[2].': '.$token[1];
            }
        }
    }

    expect($offenders)->toBe([]);
});
