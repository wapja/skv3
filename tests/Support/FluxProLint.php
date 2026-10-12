<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * §6.7 / RL-13: Pro-only Flux components may appear only in the true branch of `@if (Skv::hasFluxPro())`.
 * A tag is free when free Flux (or a project override) has its view, or when it is an icon; `flux:select`
 * with a Pro variant is Pro (DEC-25).
 *
 * An `@else` / `@elseif` ends the Pro branch of its `@if`; `@elseif (Skv::hasFluxPro())` is not a supported
 * pattern, so the free branch is always the `@else`. Tags that span several lines are matched by their first
 * line only, so the `variant` attribute of a `flux:select` must sit on its opening line.
 */
final class FluxProLint
{
    private const string PRO_CONDITION = '/^@(if|elseif)\s*\(\s*\\\\?(App\\\\Support\\\\)?Skv::hasFluxPro\(\)\s*\)/';

    /** @var list<string>|null */
    private static ?array $free = null;

    /** @return list<string> */
    public static function violations(string $blade): array
    {
        $violations = [];
        $stack = []; // each entry: ['pro' => bool, 'else' => bool]

        foreach (preg_split('/\R/', $blade) ?: [] as $index => $line) {
            foreach (self::tokens($line) as $token) {
                if (str_starts_with($token, '@')) {
                    self::directive($token, $stack);

                    continue;
                }

                if (! self::insidePro($stack) && self::isPro($token)) {
                    $violations[] = 'line '.($index + 1).': '.$token;
                }
            }
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

    /** @return list<string> Blade directives and opening flux tags, in order. */
    private static function tokens(string $line): array
    {
        preg_match_all('/@(?:if|elseif|else|endif|unless|endunless)\b(?:\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?|<flux:[a-z0-9.\-]+(?:\s[^>]*)?>?/i', $line, $matches);

        return $matches[0];
    }

    /** @param  list<array{pro: bool, else: bool}>  $stack */
    private static function directive(string $token, array &$stack): void
    {
        if (preg_match('/^@(if|unless)\b/', $token) === 1) {
            $isPro = preg_match(self::PRO_CONDITION, $token) === 1;
            $stack[] = ['pro' => $isPro && str_starts_with($token, '@if'), 'else' => false];

            return;
        }

        if (preg_match('/^@(else|elseif)\b/', $token) === 1 && $stack !== []) {
            // One whole-frame assignment keeps the frame's array shape for Larastan (parameterByRef.type).
            $last = array_key_last($stack);
            $stack[$last] = ['pro' => false, 'else' => true];

            return;
        }

        if (preg_match('/^@(endif|endunless)\b/', $token) === 1) {
            array_pop($stack);
        }
    }

    /** @param  list<array{pro: bool, else: bool}>  $stack */
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
