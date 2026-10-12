<?php

declare(strict_types=1);

namespace App\Support;

/**
 * §6.7: Blade resolves every `<flux:…>` tag while it compiles a view, not when it renders it, so a Pro-only tag
 * inside `@if (Skv::hasFluxPro())` still fails to compile when Flux Pro is absent. Without Pro, this removes the
 * true branch of each such `@if` before Blade compiles the view (also for `view:cache`). The free `@else` branch
 * stays, and the line count is kept so compiled line numbers still match the source.
 */
final class FluxProBranches
{
    private const string DIRECTIVES = '/@(?:if|elseif|else|endif|unless|endunless)\b(?:\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/';

    private const string PRO_IF = '/^@if\s*\(\s*\\\\?(App\\\\Support\\\\)?Skv::hasFluxPro\(\)\s*\)$/';

    /** Rendered only when Flux Pro was installed after the view was compiled without it. */
    private const string STALE = "<?php throw new \\LogicException('Flux Pro was installed after this view was compiled. Run php artisan view:clear.'); ?>";

    public static function strip(string $blade): string
    {
        if (! str_contains($blade, 'hasFluxPro')) {
            return $blade;
        }

        // Directives inside Blade comments are not directives: blank the comments (same length) before scanning.
        $scanned = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $comment): string => str_repeat(' ', strlen($comment[0])), $blade);

        preg_match_all(self::DIRECTIVES, $scanned, $matches, PREG_OFFSET_CAPTURE);

        $cuts = [];
        $stack = []; // per open @if / @unless: where its Pro branch content starts, or null

        foreach ($matches[0] as [$token, $offset]) {
            if (preg_match('/^@(if|unless)\b/', $token) === 1) {
                $stack[] = preg_match(self::PRO_IF, $token) === 1 ? $offset + strlen($token) : null;

                continue;
            }

            if ($stack === []) {
                continue;
            }

            $last = array_key_last($stack);

            if ($stack[$last] !== null) {
                $cuts[] = [$stack[$last], $offset];
                $stack[$last] = null;
            }

            if (preg_match('/^@(endif|endunless)\b/', $token) === 1) {
                array_pop($stack);
            }
        }

        return self::cut($blade, $cuts);
    }

    /** @param  list<array{int, int}>  $cuts  [start, end) byte ranges; a range inside another one is skipped. */
    private static function cut(string $blade, array $cuts): string
    {
        usort($cuts, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $kept = [];
        $end = -1;

        foreach ($cuts as $cut) {
            if ($cut[0] >= $end) {
                $kept[] = $cut;
                $end = $cut[1];
            }
        }

        foreach (array_reverse($kept) as [$start, $stop]) {
            $removed = substr($blade, $start, $stop - $start);
            $blade = substr($blade, 0, $start).self::STALE.str_repeat("\n", substr_count($removed, "\n")).substr($blade, $stop);
        }

        return $blade;
    }
}
