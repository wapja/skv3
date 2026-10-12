<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * §6.7 / RL-13: Pro-only Flux components may appear only in the true branch of `@if (Skv::hasFluxPro())`.
 * A tag is free when free Flux (or a project override) has its view, or when it is an icon; `flux:select`
 * with a Pro variant is Pro (DEC-25).
 *
 * Each Pro `@if` also needs a free `@else` that renders something and binds the same `wire:model` properties
 * as the Pro branch (names only, modifiers ignored). A feature without a free variant (§6.7: the ⌘K palette,
 * the sign-in trend) is marked with a `{{-- pro-only … --}}` comment right before its `@if` instead.
 *
 * An `@else` / `@elseif` ends the Pro branch of its `@if`; `@elseif (Skv::hasFluxPro())` is not a supported
 * pattern, so the free branch is always the `@else`. Tags that span several lines are matched by their first
 * line only, so the `variant` attribute of a `flux:select` must sit on its opening line.
 *
 * @phpstan-type Frame array{pro: bool, else: bool, hasElse: bool, marked: bool, line: int, freeContent: bool, models: array{0: list<string>, 1: list<string>}}
 */
final class FluxProLint
{
    private const string PRO_CONDITION = '/^@(if|elseif)\s*\(\s*\\\\?(App\\\\Support\\\\)?Skv::hasFluxPro\(\)\s*\)/';

    private const string WIRE_MODEL = '/\bwire:model(?:\.[\w.-]+)?\s*=\s*"([^"]*)"/';

    /** @var list<string>|null */
    private static ?array $free = null;

    /** @return list<string> */
    public static function violations(string $blade): array
    {
        $violations = [];
        /** @var list<Frame> $stack */
        $stack = []; // each open @if / @unless; only a Pro @if collects its branches
        $marked = false; // a pro-only comment precedes the next @if

        foreach (preg_split('/\R/', $blade) ?: [] as $index => $line) {
            $number = $index + 1;
            $cursor = 0;

            foreach (self::tokens($line) as [$token, $position]) {
                $stack = self::withContent($stack, substr($line, $cursor, $position - $cursor));
                $cursor = $position + strlen($token);

                if (str_starts_with($token, '{{--')) {
                    $marked = true;

                    continue;
                }

                if (str_starts_with($token, '@')) {
                    if (preg_match('/^@(if|unless)\b/', $token) === 1) {
                        $pro = str_starts_with($token, '@if') && preg_match(self::PRO_CONDITION, $token) === 1;
                        $stack[] = ['pro' => $pro, 'else' => false, 'hasElse' => false, 'marked' => $pro && $marked, 'line' => $number, 'freeContent' => false, 'models' => [[], []]];
                        $marked = false;
                    } elseif (preg_match('/^@(else|elseif)\b/', $token) === 1 && $stack !== []) {
                        $last = array_key_last($stack);
                        $stack[$last]['else'] = true;
                        $stack[$last]['hasElse'] = $stack[$last]['hasElse'] || ! str_starts_with($token, '@elseif');
                    } elseif (preg_match('/^@(endif|endunless)\b/', $token) === 1 && $stack !== []) {
                        array_push($violations, ...self::branchViolations(array_pop($stack)));
                    }

                    continue;
                }

                $stack = self::withContent($stack, $token);

                if (! self::insidePro($stack) && self::isPro($token)) {
                    $violations[] = "line {$number}: {$token}";
                }
            }

            $stack = self::withContent($stack, substr($line, $cursor));
        }

        return $violations;
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

    /** @return list<array{string, int}> Pro-only markers, Blade directives and opening flux tags, with their byte offsets, in order. */
    private static function tokens(string $line): array
    {
        preg_match_all('/\{\{--\s*pro-only\b.*?--\}\}|@(?:if|elseif|else|endif|unless|endunless)\b(?:\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?|<flux:[a-z0-9.\-]+(?:\s[^>]*)?>?/i', $line, $matches, PREG_OFFSET_CAPTURE);

        return $matches[0];
    }

    /**
     * Records markup in the current branch of the innermost Pro `@if`: whether the free branch renders anything
     * (Blade comments do not count) and which `wire:model` properties each branch binds.
     *
     * @param  list<Frame>  $stack
     * @return list<Frame>
     */
    private static function withContent(array $stack, string $text): array
    {
        $innermost = array_key_last(array_filter($stack, fn (array $frame): bool => $frame['pro']));

        if ($innermost === null || trim($text) === '') {
            return $stack;
        }

        $branch = $stack[$innermost]['else'] ? 1 : 0;

        if ($branch === 1 && trim((string) preg_replace('/\{\{--.*?--\}\}/', '', $text)) !== '') {
            $stack[$innermost]['freeContent'] = true;
        }

        preg_match_all(self::WIRE_MODEL, $text, $models);

        foreach ($models[1] as $model) {
            $stack[$innermost]['models'][$branch][] = $model;
        }

        return $stack;
    }

    /**
     * @param  Frame  $frame
     * @return list<string>
     */
    private static function branchViolations(array $frame): array
    {
        if (! $frame['pro']) {
            return [];
        }

        $at = "line {$frame['line']}: @if (Skv::hasFluxPro())";

        if (! $frame['hasElse']) {
            return $frame['marked'] ? [] : ["{$at} has no free @else; mark a feature without a free variant (§6.7) with {{-- pro-only --}}"];
        }

        if (! $frame['freeContent']) {
            return ["{$at} has an empty free @else"];
        }

        [$pro, $free] = array_map(function (array $models): string {
            $models = array_values(array_unique($models));
            sort($models);

            return implode(', ', $models);
        }, $frame['models']);

        return $pro === $free ? [] : ["{$at} binds wire:model [{$pro}] in the Pro branch but [{$free}] in the free @else"];
    }

    /** @param  list<Frame>  $stack */
    private static function insidePro(array $stack): bool
    {
        return array_any($stack, fn (array $frame): bool => $frame['pro'] && ! $frame['else']);
    }

    private static function isPro(string $tag): bool
    {
        preg_match('/^<flux:([a-z0-9.\-]+)/i', $tag, $match);
        $name = strtolower($match[1] ?? '');

        if ($name === '' || str_starts_with($name, 'icon.') || $name === 'icon') {
            return false;
        }

        if ($name === 'select' && preg_match('/\bvariant\s*=\s*"(listbox|combobox|custom)"/', $tag) === 1) {
            return true;
        }

        return ! in_array($name, self::freeComponents(), true);
    }
}
