<?php

declare(strict_types=1);

/** covers §6.7, §6.8 */

use App\Support\Skv;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Blade;

afterEach(fn () => Skv::useFluxPro(null));

it('detects Flux Pro from the installed packages (§6.7)', function (): void {
    expect(Skv::hasFluxPro())->toBe(InstalledVersions::isInstalled('livewire/flux-pro'));
});

it('lets tests force the free or the Pro branch', function (): void {
    Skv::useFluxPro(false);
    expect(Skv::hasFluxPro())->toBeFalse();

    Skv::useFluxPro(true);
    expect(Skv::hasFluxPro())->toBeTrue();

    Skv::useFluxPro(null);
    expect(Skv::hasFluxPro())->toBe(InstalledVersions::isInstalled('livewire/flux-pro'));
});

it('reads module flags from config and treats unknown modules as off (§6.8)', function (): void {
    config(['skv.modules.passkeys' => false]);
    config(['skv.modules.impersonation' => true]);

    expect(Skv::moduleEnabled('passkeys'))->toBeFalse()
        ->and(Skv::passkeysEnabled())->toBeFalse()
        ->and(Skv::moduleEnabled('impersonation'))->toBeTrue()
        ->and(Skv::impersonationEnabled())->toBeTrue()
        ->and(Skv::moduleEnabled('unknown'))->toBeFalse();
});

it('is available to Blade as a global alias', function (): void {
    expect(Blade::render('{{ Skv::moduleEnabled("unknown") ? "on" : "off" }}'))->toBe('off');
});
