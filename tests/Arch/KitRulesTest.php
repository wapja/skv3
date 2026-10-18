<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Str;

arch('app code reads configuration, never the environment')
    ->expect(['App', 'Database'])
    ->not->toUse(['env']);

arch('no debugging leftovers')
    ->expect(['App', 'Database', 'Tests'])
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump']);

arch('strict types everywhere in app code')
    ->expect('App')
    ->toUseStrictTypes();

it('has no debugging leftovers in tests, migrations, views and routes (FU2-12)', function (): void {
    // The arch rule above sees namespaced classes only: Livewire's multi-file classes, Blade (@dd, @dump), route files
    // and anonymous migrations are scanned as text. A namespaced \dd() counts, and so do the dd and dump helpers of
    // responses, queries and collections (->ddHeaders(), ->dumpSession(), ->ddRawSql()).
    $pattern = '/(?<![\w$>:-])(dd|dump|ray|var_dump)\s*\(|->(dd|dump)\w*\s*\(/';

    foreach (['dd($user);', '\\dd($user);', 'dump($user);', '\\dump($user);', 'ray($user);', 'var_dump($user);', '@dd($user)', '$response->ddHeaders();', '$response->dumpSession();', '$query->ddRawSql();', '$users->dump();'] as $form) {
        expect(preg_match($pattern, $form))->toBe(1, "The debug rule misses [{$form}].");
    }

    foreach (['$this->address($user);', 'array($user);', 'Str::ddx($user);', 'X-ray (beta)', '$query->toRawSql();'] as $form) {
        expect(preg_match($pattern, $form))->toBe(0, "The debug rule flags [{$form}].");
    }

    $files = array_values(array_diff(kitSourceFiles('tests', 'database/migrations', 'resources/views', 'routes'), ['tests/Arch/KitRulesTest.php']));

    expect(filesMatching($files, $pattern))->toBe([]);
});

it('formats sizes without ext-intl: no Illuminate\Support\Number in app code or views (CD-9, F20)', function (): void {
    // The class name, a Number:: call, and the global alias `\Number::`, which a namespaced class reaches (M24).
    expect(filesMatching(kitSourceFiles('app', 'resources/views'), '/Illuminate\\\\Support\\\\Number\b|(?<![\w\\\\$>:])\\\\?Number::/'))->toBe([]);
});

it('constrains routes by domain only in the Fortify groups (G5-07)', function (): void {
    // Route::tenant() / Route::central() attach host middleware instead (§6.2). routes/fortify.php and
    // routes/passkeys.php keep Fortify's own domain + prefix group, so their replacements match the vendor routes
    // (FU3-3, CD-3). An empty Route::domain() in a comment does not match. bootstrap/app.php registers routes too
    // (withRouting(then:)); a chained ->domain(...) and a double-quoted "domain" key count, $this->domain() does not.
    $files = array_values(array_filter(
        kitSourceFiles('routes', 'app', 'bootstrap'),
        fn (string $path): bool => ! str_starts_with($path, 'bootstrap/cache/'),
    ));

    expect(filesMatching($files, '/(?:Route::|(?<!\$this)->)domain\s*\(\s*[^)\s]|[\'"]domain[\'"]\s*=>/'))
        ->toBe(['routes/fortify.php', 'routes/passkeys.php']);
});

it('starts external commands only through Process: no exec() and friends, no backticks, no shell strings (F19, L7b-70)', function (): void {
    $banned = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];

    /** @return list<string> */
    $offendersIn = function (string $code) use ($banned): array {
        // Tokens, not text: comments, strings and docblocks may name these functions and use backticks.
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $offenders = [];

        foreach ($tokens as $index => $token) {
            if ($token->text === '`') {
                $offenders[] = 'backticks';

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
                $offenders[] = $token->text.'()';
            }

            // L7b-70: a string command runs through a shell (Symfony's Process::fromShellCommandline()); an argv never does.
            if ($token->is(T_STRING) && $token->text === 'fromShellCommandline') {
                $offenders[] = 'fromShellCommandline()';
            }

            $class = $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) ? Str::afterLast($token->text, '\\') : null;
            $method = $tokens[$index + 2] ?? null;
            $argument = $tokens[$index + 4] ?? null;

            if ($class === 'Process' && $next?->is(T_DOUBLE_COLON) === true
                && $method instanceof PhpToken && in_array($method->text, ['run', 'start', 'command'], true)
                && ($tokens[$index + 3] ?? null)?->text === '('
                && $argument instanceof PhpToken && ($argument->is([T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC]) || $argument->text === '"')) {
                $offenders[] = 'Process::'.$method->text.'() with a string';
            }
        }

        return $offenders;
    };

    foreach (['exec(\'ls\');', '\\shell_exec(\'ls\');', '$out = `ls`;', 'Symfony\\Component\\Process\\Process::fromShellCommandline(\'ls\');', 'Process::run(\'ls -la\');', 'Process::start("npm run {$script}");', '\\Illuminate\\Support\\Facades\\Process::command(\'ls\');'] as $form) {
        expect($offendersIn('<?php '.$form))->not->toBe([], "The rule misses [{$form}].");
    }

    foreach (['Process::run([\'ls\', \'-la\']);', '$schedule->command(\'backup:run\');', '$runner->exec($step);', 'Process::path($dir)->run([$herd, \'links\']);', '// exec(\'ls\') in a comment'] as $form) {
        expect($offendersIn('<?php '.$form))->toBe([], "The rule flags [{$form}].");
    }

    // resources/views holds the multi-file components' classes, the System page's among them (M3); Blade is no PHP.
    // bootstrap/ (without the compiled cache) and scripts/ run kit code too (L7b-70).
    $files = array_filter(
        kitSourceFiles('app', 'tests', 'routes', 'database', 'config', 'resources/views', 'bootstrap', 'scripts'),
        fn (string $path): bool => ! str_ends_with($path, '.blade.php') && ! str_starts_with($path, 'bootstrap/cache/'),
    );
    $offenders = [];

    foreach ($files as $path) {
        foreach ($offendersIn((string) file_get_contents(base_path($path))) as $offender) {
            $offenders[] = $path.': '.$offender;
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

it('fakes processes by name, never with a catch-all, so the stray-process guard stays on (g2-10, RL-12)', function (): void {
    $files = array_values(array_diff(kitSourceFiles('tests'), ['tests/Arch/KitRulesTest.php']));

    // Factory::fake() fakes every command for no argument, a closure, a '*' key or a list value (a numeric key is '*').
    // This catches the literal forms only: a bare call, a closure, an array that starts with a list value, and a
    // literal '*' key before the statement's first ';' (M2). The rest ('**', a list value after a named key,
    // Process::fake($fakes)) fails at runtime: RecordingProcessFactory::catchAllPatterns() in tests/Pest.php (L7b-77).
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
