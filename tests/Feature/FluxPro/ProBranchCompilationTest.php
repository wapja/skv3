<?php

declare(strict_types=1);

/** covers §6.7 */

use App\Support\FluxProBranches;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Blade;

it('removes the Pro branch and keeps the free branch and the line count', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            <flux:tabs wire:model.live="view">
                <flux:tab name="active">Active</flux:tab>
            </flux:tabs>
        @else
            <flux:radio.group wire:model.live="view" variant="segmented" />
        @endif
        BLADE;

    $stripped = FluxProBranches::strip($blade);

    expect($stripped)->not->toContain('flux:tabs')->not->toContain('flux:tab ')
        ->toContain('@if (Skv::hasFluxPro())')
        ->toContain('<flux:radio.group wire:model.live="view" variant="segmented" />')
        ->and(substr_count($stripped, "\n"))->toBe(substr_count($blade, "\n"));
});

it('keeps conditions nested in or around a Pro branch intact and ignores directives in comments', function (): void {
    $blade = <<<'BLADE'
        {{-- @if (Skv::hasFluxPro()) in a comment is no branch --}}
        @if ($this->show)
            @if (\App\Support\Skv::hasFluxPro())
                @if ($this->loginTrend !== null)
                    <flux:chart :value="$this->loginTrend" />
                @endif
            @endif
            <flux:card data-test="kept" />
        @endif
        BLADE;

    $stripped = FluxProBranches::strip($blade);

    expect($stripped)->not->toContain('flux:chart')->not->toContain('$this->loginTrend !== null')
        ->toContain('@if ($this->show)')
        ->toContain('<flux:card data-test="kept" />')
        ->and(substr_count($stripped, '@endif'))->toBe(2)
        ->and(FluxProBranches::strip('<flux:button>Save</flux:button>'))->toBe('<flux:button>Save</flux:button>');
});

it('compiles every view of the kit without Flux Pro, as view:cache does (§6.7)', function (): void {
    $failures = [];

    foreach (kitSourceFiles('resources/views') as $path) {
        if (! str_ends_with($path, '.blade.php')) {
            continue;
        }

        try {
            Blade::compileString((string) file_get_contents(base_path($path)));
        } catch (Throwable $exception) {
            $failures[] = "{$path}: {$exception->getMessage()}";
        }
    }

    expect($failures)->toBe([]);
})->skip(fn (): bool => InstalledVersions::isInstalled('livewire/flux-pro'), 'Flux Pro is installed: its tags compile anyway');
