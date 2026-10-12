<?php

declare(strict_types=1);

namespace App\Support;

use Composer\InstalledVersions;

/**
 * Kit-wide switches for views and code (§6.7, §6.8): the optional modules and whether Flux Pro is installed.
 * Registered as the global alias `Skv` so Blade can write `@if (Skv::hasFluxPro())` (RL-1).
 */
final class Skv
{
    private static ?bool $fluxPro = null;

    private static ?bool $fluxProOverride = null;

    /** Whether livewire/flux-pro is installed; Pro-only components appear only inside this branch (§6.7). */
    public static function hasFluxPro(): bool
    {
        if (self::$fluxProOverride !== null) {
            return self::$fluxProOverride;
        }

        return self::$fluxPro ??= InstalledVersions::isInstalled('livewire/flux-pro');
    }

    /** Tests only: force the free (false) or the Pro (true) branch; null restores detection. */
    public static function useFluxPro(?bool $installed): void
    {
        self::$fluxProOverride = $installed;
    }

    /** Whether an optional module (§6.8) is on; unknown modules are off. */
    public static function moduleEnabled(string $module): bool
    {
        return in_array($module, ['passkeys', 'impersonation', 'backups'], true)
            && (bool) config("skv.modules.{$module}", false);
    }

    public static function passkeysEnabled(): bool
    {
        return self::moduleEnabled('passkeys');
    }

    public static function impersonationEnabled(): bool
    {
        return self::moduleEnabled('impersonation');
    }
}
