<?php

declare(strict_types=1);

/** covers §5.3.4 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

it('renders without Vite or public/build (§5.3.4)', function (): void {
    Vite::useManifestFilename('missing-'.Str::uuid().'.json');

    $html = Blade::render('<x-layouts::setup title="Setup"><p data-test="slot">body</p></x-layouts::setup>');

    expect($html)->toContain('<!DOCTYPE html>')
        ->toContain('<style>')
        ->toContain('[data-flux-')
        ->toContain('data-test="slot"')
        ->not->toContain('/build/')
        ->not->toContain('@vite')
        ->not->toContain('fonts.bunny.net');
});

it('builds the committed stylesheet with build:setup from the kit theme (OD-1, RL-15)', function (): void {
    /** @var array{scripts: array<string, string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $css = (string) file_get_contents(resource_path('setup/setup.css'));

    expect($package['scripts']['build:setup'] ?? null)->toBe('vite build --config vite.setup.config.js')
        ->and((string) file_get_contents(base_path('vite.setup.config.js')))->toContain("assetFileNames: 'setup.css'")
        ->and((string) file_get_contents(resource_path('css/setup.css')))->toContain("@import './theme.css'")
        ->and((string) file_get_contents(resource_path('css/setup.css')))->toContain("@source not '../../vendor/livewire/flux-pro'")
        ->and((string) file_get_contents(resource_path('css/app.css')))->toContain("@import './theme.css'")
        ->and(strlen($css))->toBeGreaterThan(10_000)
        ->and($css)->toContain('--color-accent');
});
