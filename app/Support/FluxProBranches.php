<?php

declare(strict_types=1);

namespace App\Support;

/**
 * §6.7: Blade resolves every `<flux:…>` tag while it compiles a view, not when it renders it, so a Pro-only tag
 * inside `@if (Skv::hasFluxPro())` still fails to compile when Flux Pro is absent. Without Pro, this removes the
 * true branch of each such `@if` before Blade compiles the view (also for `view:cache`). The free `@else` branch
 * stays, and the line count is kept so compiled line numbers still match the source. The block structure comes from
 * BladeDirectives, so an `@else` of a nested `@auth`, `@can`, `@isset`, `@error` … never ends the Pro branch (G5-09);
 * the Pro lint reads the same branches().
 *
 * @phpstan-type ProBranch array{if: int, start: int, end: int, free: int|null, close: int, hasElse: bool}
 */
final class FluxProBranches
{
    private const string PRO_IF = '/^@if\s*\(\s*\\\\?(App\\\\Support\\\\)?Skv::hasFluxPro\(\)\s*\)$/';

    /** Rendered only when Flux Pro was installed after the view was compiled without it. */
    private const string STALE = "<?php throw new \\LogicException('Flux Pro was installed after this view was compiled. Run php artisan view:clear.'); ?>";

    public static function strip(string $blade): string
    {
        if (! str_contains($blade, 'hasFluxPro')) {
            return $blade;
        }

        return self::cut($blade, array_map(fn (array $branch): array => [$branch['start'], $branch['end']], self::branches($blade)));
    }

    /**
     * Every `@if (Skv::hasFluxPro())` that Blade would close, in source order, as byte offsets: `if` where the
     * directive starts, [`start`, `end`) the Pro branch, `free` where the content after the first branch directive
     * (`@else`, `@elseif` …) starts or null without one, `close` where its closing directive starts, and whether a
     * plain `@else` belongs to it.
     *
     * @return list<ProBranch>
     */
    public static function branches(string $blade): array
    {
        $branches = [];
        $stack = []; // per open block: its Pro branch while it is collected, or null

        foreach (BladeDirectives::tokens($blade) as $directive) {
            if ($directive['kind'] === 'open') {
                $stack[] = $directive['name'] === 'if' && preg_match(self::PRO_IF, $directive['token']) === 1
                    ? ['if' => $directive['offset'], 'start' => $directive['offset'] + strlen($directive['token']), 'end' => null, 'free' => null, 'hasElse' => false]
                    : null;

                continue;
            }

            $last = array_key_last($stack);

            if ($last === null) {
                continue;
            }

            if ($stack[$last] !== null) {
                $stack[$last]['end'] ??= $directive['offset'];
            }

            if ($directive['kind'] === 'branch') {
                if ($stack[$last] !== null) {
                    $stack[$last]['free'] ??= $directive['offset'] + strlen($directive['token']);
                    $stack[$last]['hasElse'] = $stack[$last]['hasElse'] || $directive['name'] === 'else';
                }

                continue;
            }

            $frame = array_pop($stack);

            if ($frame !== null) {
                $branches[] = [
                    'if' => $frame['if'],
                    'start' => $frame['start'],
                    'end' => $frame['end'] ?? $directive['offset'],
                    'free' => $frame['free'],
                    'close' => $directive['offset'],
                    'hasElse' => $frame['hasElse'],
                ];
            }
        }

        usort($branches, fn (array $a, array $b): int => $a['if'] <=> $b['if']);

        return $branches;
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
