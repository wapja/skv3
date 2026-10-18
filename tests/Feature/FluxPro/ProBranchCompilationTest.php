<?php

declare(strict_types=1);

/** covers §6.7 */

use App\Support\FluxProBranches;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Blade;
use Tests\Support\FluxProLint;

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

it('keeps cutting a Pro branch past the @else of a nested block directive, as the lint reads it (G5-09)', function (string $open, string $close): void {
    $blade = <<<BLADE
        @if (Skv::hasFluxPro())
            {$open}
                <p>inner</p>
            @else
                <flux:chart />
            {$close}
            <flux:date-picker wire:model="from" />
        @else
            <flux:input type="date" wire:model="from" />
        @endif
        BLADE;

    $stripped = FluxProBranches::strip($blade);

    expect($stripped)->not->toContain('<flux:chart')->not->toContain('<flux:date-picker')
        ->toContain('<flux:input type="date" wire:model="from" />')
        ->and(substr_count($stripped, "\n"))->toBe(substr_count($blade, "\n"))
        ->and(FluxProLint::violations($blade))->toBe([]);
})->with([
    '@unless' => ['@unless ($compact)', '@endunless'],
    '@auth' => ['@auth', '@endauth'],
    '@guest' => ['@guest', '@endguest'],
    '@can' => ["@can('users.view')", '@endcan'],
    '@cannot' => ["@cannot('users.view')", '@endcannot'],
    '@canany' => ["@canany(['users.view', 'roles.view'])", '@endcanany'],
    '@isset' => ['@isset($user)', '@endisset'],
    '@empty with arguments' => ['@empty($users)', '@endempty'],
    '@env' => ["@env('local')", '@endenv'],
    '@production' => ['@production', '@endproduction'],
    '@session' => ["@session('status')", '@endsession'],
    '@error' => ["@error('email')", '@enderror'],
    '@hasSection' => ["@hasSection('title')", '@endif'],
    'upper case' => ['@AUTH', '@ENDAUTH'],
]);

it('reads @forelse\'s @empty, escaped directives and @verbatim blocks as Blade does (G5-09)', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            @forelse ($rows as $row)
                <flux:chart />
            @empty
                <p>none</p>
            @endforelse
            <p>@@else is text, and so is mail@else.test</p>
            @verbatim @else @endverbatim
            <flux:date-picker />
        @else
            <p>free</p>
        @endif
        BLADE;

    $stripped = FluxProBranches::strip($blade);

    expect($stripped)->not->toContain('<flux:chart')->not->toContain('<flux:date-picker')
        ->toContain('<p>free</p>')
        ->and(substr_count($stripped, "\n"))->toBe(substr_count($blade, "\n"))
        // The lint reads the same branches as the stripper on exactly these cases (§6.7, G5-09).
        ->and(FluxProLint::violations($blade))->toBe([]);
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
