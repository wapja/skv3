<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The block structure of a Blade template as Blade compiles it (§6.7, G5-09): every built-in directive that compiles
 * to a PHP `if` opens a block that an `@else` can belong to, and an `@else` always belongs to the innermost open
 * block. Directive names are matched without regard to case, as Blade's compile methods are; `@@if` is an escaped
 * literal, `email@if` is text, and the arguments are read up to their balanced closing parenthesis, outside quotes.
 * Blade comments, `@verbatim` and `@php` blocks are never compiled, so nothing inside them counts.
 * FluxProBranches (the stripper) and the Pro lint (tests/Support/FluxProLint.php) read templates only through this.
 *
 * Limit: only Blade's built-in conditionals are known. A condition a package registers with Blade::if (spatie's
 * `@role`, `@haspermission`, `@hasanyrole`, …) opens a block Blade compiles but this grammar does not see, so its
 * `@else` would be taken for the enclosing block's: never put such a condition with an `@else` inside a Pro branch
 * (the Pro lint flags the leftover tag when one is stripped too early).
 */
final class BladeDirectives
{
    /** Directives that compile to `if (…):`. `@empty` opens a block only with arguments; without, it is @forelse's branch. */
    public const array OPENERS = [
        'if', 'unless', 'isset', 'empty', 'auth', 'guest', 'can', 'cannot', 'canany', 'env', 'production',
        'session', 'error', 'context', 'hassection', 'sectionmissing', 'hasstack', 'once', 'pushif', 'pushonce', 'prependonce',
    ];

    /** Directives that start another branch of the innermost open block. */
    public const array BRANCHES = ['else', 'elseif', 'elseauth', 'elseguest', 'elsecan', 'elsecannot', 'elsecanany', 'elsepushif', 'elsepush'];

    /** Directives that compile to `endif;`. `@hasSection`, `@sectionMissing` and `@hasstack` close with `@endif`. */
    public const array CLOSERS = [
        'endif', 'endunless', 'endisset', 'endempty', 'endauth', 'endguest', 'endcan', 'endcannot', 'endcanany', 'endenv',
        'endproduction', 'endsession', 'enderror', 'endcontext', 'endonce', 'endpushif', 'endpushonce', 'endprependonce',
    ];

    /**
     * The template with Blade comments, `@verbatim` blocks and `@php` blocks replaced by spaces of the same length.
     * Newlines are kept, so offsets and line numbers still match the source.
     */
    public static function blank(string $blade): string
    {
        return (string) preg_replace_callback(
            '/\{\{--.*?--\}\}|(?<!@)@verbatim\b.*?@endverbatim\b|(?<!@)@php\b(?![ \t]*\().*?@endphp\b/si',
            fn (array $block): string => (string) preg_replace('/[^\n]/', ' ', $block[0]),
            $blade,
        );
    }

    /**
     * The block directives of a template, in source order: what each one does to the block structure, its lower-case
     * name, its full text (arguments included) and its byte offset in the source.
     *
     * @return list<array{kind: 'open'|'branch'|'close', name: string, token: string, offset: int}>
     */
    public static function tokens(string $blade): array
    {
        $scanned = self::blank($blade);
        preg_match_all('/(?<![\w@])@([a-z]+)\b/i', $scanned, $matches, PREG_OFFSET_CAPTURE);

        $tokens = [];

        foreach ($matches[1] as [$name, $nameOffset]) {
            $name = strtolower($name);
            $offset = $nameOffset - 1;
            $end = self::argumentsEnd($scanned, $nameOffset + strlen($name));

            $kind = match (true) {
                in_array($name, self::OPENERS, true) && ($name !== 'empty' || $end !== null) => 'open',
                in_array($name, self::BRANCHES, true) => 'branch',
                in_array($name, self::CLOSERS, true) => 'close',
                default => null,
            };

            if ($kind !== null) {
                $length = ($end ?? $nameOffset + strlen($name)) - $offset;
                $tokens[] = ['kind' => $kind, 'name' => $name, 'token' => substr($blade, $offset, $length), 'offset' => $offset];
            }
        }

        return $tokens;
    }

    /** The offset right after the directive's balanced `(…)`, or null when no arguments follow on the same line. */
    private static function argumentsEnd(string $scanned, int $from): ?int
    {
        $position = $from + strspn($scanned, " \t", $from);

        if (($scanned[$position] ?? '') !== '(') {
            return null;
        }

        $depth = 0;
        $quote = null;
        $length = strlen($scanned);

        for ($index = $position; $index < $length; $index++) {
            $character = $scanned[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $index + 1;
            }
        }

        return null;
    }
}
