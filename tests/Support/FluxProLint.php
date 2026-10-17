<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\BladeDirectives;
use App\Support\FluxProBranches;
use Illuminate\Support\Facades\File;

/**
 * §6.7 / RL-13: Pro-only Flux components may appear only in the true branch of `@if (Skv::hasFluxPro())`.
 * A tag is free when free Flux (or a project override) has its view, or when it is an icon; `flux:select`
 * with a Pro variant (`variant` or `:variant`, either quote) is Pro (DEC-25).
 *
 * Each Pro `@if` also needs a free `@else` that renders something and binds the same `wire:model` properties
 * as the Pro branch (names only, modifiers ignored). A feature without a free variant (§6.7: the ⌘K palette,
 * the sign-in trend) is marked with a `{{-- pro-only … --}}` comment right before its `@if` (on the same line or
 * the line above) instead.
 *
 * The branches come from FluxProBranches::branches(), the stripper's own grammar (G5-09, G5-10): every block
 * directive that can take an `@else` nests, Blade comments are no directives, and a tag is read as a whole, also
 * when it spans several lines. An `@else` / `@elseif` ends the Pro branch of its `@if`; `@elseif (Skv::hasFluxPro())`
 * is not a supported pattern, so the free branch is always the `@else`.
 *
 * @phpstan-import-type ProBranch from FluxProBranches
 */
final class FluxProLint
{
    private const string FLUX_TAG = '/<flux:[a-z0-9.\-]+(?:\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?>?/i';

    private const string PRO_VARIANT = '/\s:?variant\s*=\s*(["\'])(?:(?!\1).)*?\b(?:listbox|combobox|custom)\b/is';

    private const string PRO_ONLY_MARKER = '/\{\{--\s*pro-only\b(?:(?!--\}\}).)*--\}\}[ \t]*(?:\R[ \t]*)?\z/s';

    private const string WIRE_MODEL = '/\bwire:model(?:\.[\w.-]+)?\s*=\s*"([^"]*)"/';

    /** @var list<string>|null */
    private static ?array $free = null;

    /** @return list<string> */
    public static function violations(string $blade): array
    {
        $scanned = BladeDirectives::blank($blade);
        $branches = FluxProBranches::branches($blade);
        $found = []; // [offset, message]

        preg_match_all(self::FLUX_TAG, $scanned, $tags, PREG_OFFSET_CAPTURE);

        foreach ($tags[0] as [$tag, $offset]) {
            if (self::isPro($tag) && ! self::insideProBranch($branches, $offset)) {
                $found[] = [$offset, 'line '.self::line($blade, $offset).': '.preg_replace('/\s+/', ' ', $tag)];
            }
        }

        $content = self::withoutDirectives($blade, $scanned);

        foreach ($branches as $branch) {
            foreach (self::branchViolations($blade, $scanned, $content, $branch) as $violation) {
                $found[] = [$branch['close'], $violation];
            }
        }

        usort($found, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $violation): string => $violation[1], $found);
    }

    /** @return list<string> Free component names such as "sidebar.item" or "button". */
    public static function freeComponents(): array
    {
        if (self::$free !== null) {
            return self::$free;
        }

        $names = [];

        foreach ([base_path('vendor/livewire/flux/stubs/resources/views/flux'), resource_path('views/flux')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                $name = preg_replace(['/\.blade\.php$/', '#/index$#'], '', $relative);
                $names[] = str_replace('/', '.', (string) $name);
            }
        }

        return self::$free = array_values(array_unique($names));
    }

    /**
     * @param  ProBranch  $branch
     * @return list<string>
     */
    private static function branchViolations(string $blade, string $scanned, string $content, array $branch): array
    {
        $at = 'line '.self::line($blade, $branch['if']).': @if (Skv::hasFluxPro())';

        if (! $branch['hasElse'] || $branch['free'] === null) {
            $marked = preg_match(self::PRO_ONLY_MARKER, substr($blade, 0, $branch['if'])) === 1;

            return $marked ? [] : ["{$at} has no free @else; mark a feature without a free variant (§6.7) with {{-- pro-only --}}"];
        }

        $free = $branch['free'];

        if (trim(substr($content, $free, $branch['close'] - $free)) === '') {
            return ["{$at} has an empty free @else"];
        }

        [$pro, $freeModels] = array_map(function (string $text): string {
            preg_match_all(self::WIRE_MODEL, $text, $models);
            $names = array_values(array_unique($models[1]));
            sort($names);

            return implode(', ', $names);
        }, [substr($scanned, $branch['start'], $branch['end'] - $branch['start']), substr($scanned, $free, $branch['close'] - $free)]);

        return $pro === $freeModels ? [] : ["{$at} binds wire:model [{$pro}] in the Pro branch but [{$freeModels}] in the free @else"];
    }

    /** The scanned template with every block directive blanked too: what is left is markup. */
    private static function withoutDirectives(string $blade, string $scanned): string
    {
        foreach (BladeDirectives::tokens($blade) as $directive) {
            $scanned = substr_replace($scanned, str_repeat(' ', strlen($directive['token'])), $directive['offset'], strlen($directive['token']));
        }

        return $scanned;
    }

    /** @param  list<ProBranch>  $branches */
    private static function insideProBranch(array $branches, int $offset): bool
    {
        return array_any($branches, fn (array $branch): bool => $offset >= $branch['start'] && $offset < $branch['end']);
    }

    private static function line(string $blade, int $offset): int
    {
        return substr_count($blade, "\n", 0, $offset) + 1;
    }

    private static function isPro(string $tag): bool
    {
        preg_match('/^<flux:([a-z0-9.\-]+)/i', $tag, $match);
        $name = strtolower($match[1] ?? '');

        if ($name === '' || str_starts_with($name, 'icon.') || $name === 'icon') {
            return false;
        }

        if ($name === 'select' && preg_match(self::PRO_VARIANT, $tag) === 1) {
            return true;
        }

        return ! in_array($name, self::freeComponents(), true);
    }
}
