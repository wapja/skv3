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

it('starts external commands only through Process: no exec() and friends, no backticks (F19)', function (): void {
    $banned = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];
    $offenders = [];

    foreach (kitSourceFiles('app', 'tests', 'routes', 'database', 'config') as $path) {
        // Tokens, not text: comments, strings and docblocks may name these functions and use backticks.
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents(base_path($path))),
            fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        foreach ($tokens as $index => $token) {
            if ($token->text === '`') {
                $offenders[] = $path.': backticks';

                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            // A method or static call, a declaration or a constant of the same name is not a call of the function.
            $declaredOrMember = $previous instanceof PhpToken
                && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]);

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && in_array(strtolower(ltrim($token->text, '\\')), $banned, true)
                && $next?->text === '('
                && ! $declaredOrMember) {
                $offenders[] = $path.': '.$token->text.'()';
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

it('fakes processes by name, never with a catch-all, so the stray-process guard stays on (g2-10, RL-12)', function (): void {
    $files = array_values(array_diff(kitSourceFiles('tests'), ['tests/Arch/KitRulesTest.php']));

    // Factory::fake() fakes every command for no argument, a closure, a '*' key or a list value (a numeric key is '*'),
    // or a '*' key anywhere in the array.
    expect(filesMatching($files, '/Process::fake\(\s*(?:\)|(?:static\s+)?(?:fn|function)\b|\[\s*(?:([\'"])\*\1|Process::|(?:static\s+)?(?:fn|function)\b))|Process::fake\(\s*\[[^;]*?([\'"])\*\2\s*=>/s'))->toBe([]);
});

it('guards the setup, console, backup, health and System-page tests against stray processes (RL-12)', function (): void {
    expect((string) file_get_contents(base_path('tests/Pest.php')))
        ->toContain("->in('Feature/Setup', 'Feature/Console', 'Feature/Backups', 'Feature/Health', 'Feature/System')");
});

it('keeps Vite, public/build, web fonts and Flux Pro out of the setup pages, which render before the first build (§5.3.4, M55)', function (): void {
    $files = ['resources/views/layouts/setup.blade.php', ...kitSourceFiles('resources/views/pages/setup', 'resources/views/setup')];

    expect(filesMatching($files, '#@vite|Vite::|/build/|@fonts|hasFluxPro#'))->toBe([]);
});
