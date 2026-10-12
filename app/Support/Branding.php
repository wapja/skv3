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

    public static function logoUrl(): ?string
    {
        $logo = config('branding.logo');

        return is_string($logo) && $logo !== '' ? asset(ltrim($logo, '/')) : null;
    }

    /** Whether setup or the owner has set an accent or a logo (the "set branding" checklist item). */
    public static function isCustomised(): bool
    {
        return self::accent() !== 'neutral' || self::logoUrl() !== null;
    }
}
