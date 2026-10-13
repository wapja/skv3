<?php

declare(strict_types=1);

namespace App\Support;

use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Str;

/** The brand the UI shows (§4.1, §6.5, RL-5). */
final class Branding
{
    /** Flux's accent presets (fluxui.dev/themes) plus the kit's neutral default. */
    public const array ACCENTS = [
        'neutral', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky',
        'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ];

    /** The image types a logo may have (DEC-29). */
    public const array LOGO_EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg', 'webp'];

    /** The organisation's name on its host, the platform name on central hosts (§4.1). */
    public static function name(): string
    {
        return Tenant::current()->name ?? self::platformName();
    }

    public static function platformName(): string
    {
        return (string) config('branding.name', config('app.name', 'Laravel'));
    }

    public static function accent(): string
    {
        $accent = Str::lower(trim((string) config('branding.accent', 'neutral')));

        return in_array($accent, self::ACCENTS, true) ? $accent : 'neutral';
    }

    /** The logo's URL, or null when none is set or the value is not a valid logo path (DEC-29). */
    public static function logoUrl(): ?string
    {
        $logo = config('branding.logo');

        return is_string($logo) && self::isValidLogoPath($logo) ? asset(ltrim(trim($logo), '/')) : null;
    }

    /**
     * DEC-29: a logo is a relative path to an existing image under public/, never a URL (it would load on every
     * host): no scheme, no protocol-relative "//", no "..", and one of LOGO_EXTENSIONS.
     *
     * @param  string|null  $publicPath  the public directory to look in; null is the application's public_path()
     */
    public static function isValidLogoPath(string $path, ?string $publicPath = null): bool
    {
        $path = trim($path);

        if ($path === '' || str_starts_with($path, '//') || str_contains($path, '://') || str_contains($path, '..')) {
            return false;
        }

        $path = ltrim($path, '/');

        if ($path === '' || ! in_array(Str::lower(pathinfo($path, PATHINFO_EXTENSION)), self::LOGO_EXTENSIONS, true)) {
            return false;
        }

        return is_file(rtrim($publicPath ?? public_path(), '/').'/'.$path);
    }

    /** Whether setup or the owner has set an accent or a logo (the "set branding" checklist item). */
    public static function isCustomised(): bool
    {
        return self::accent() !== 'neutral' || self::logoUrl() !== null;
    }
}
