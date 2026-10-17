<?php

declare(strict_types=1);

/** covers §6.7 */

use Tests\Support\FluxProLint;

it('flags a Pro component outside a Pro branch', function (): void {
    expect(FluxProLint::violations("<flux:tabs wire:model=\"view\">\n</flux:tabs>"))->toBe(['line 1: <flux:tabs wire:model="view">']);
});

it('allows a Pro component inside the true branch of Skv::hasFluxPro()', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            <flux:tabs wire:model="view">
        @else
            <flux:radio.group wire:model="view" variant="segmented">
        @endif
        BLADE;

    expect(FluxProLint::violations($blade))->toBe([]);
});

it('flags a Pro component in the free branch and after the branch ends', function (): void {
    $blade = <<<'BLADE'
        @if (\App\Support\Skv::hasFluxPro())
            <flux:chart />
        @else
            <flux:date-picker />
        @endif
        <flux:command />
        BLADE;

    expect(FluxProLint::violations($blade))->toBe(['line 4: <flux:date-picker />', 'line 6: <flux:command />']);
});

it('flags a Pro select variant even though select itself is free (DEC-25)', function (): void {
    expect(FluxProLint::violations('<flux:select variant="listbox" multiple wire:model="roles">'))->toHaveCount(1)
        ->and(FluxProLint::violations('<flux:select wire:model="locale">'))->toBe([]);
});

it('treats icons and nested free components as free', function (): void {
    expect(FluxProLint::violations('<flux:icon.check-circle /> <flux:sidebar.item icon="home"> <flux:menu.radio.group>'))->toBe([]);
});

it('flags a Pro branch without a free @else unless it is marked pro-only (§6.7)', function (): void {
    $bare = <<<'BLADE'
        @if (Skv::hasFluxPro())
            <flux:chart />
        @endif
        BLADE;
    $marked = <<<'BLADE'
        {{-- pro-only (§6.7): free Flux has no sign-in trend --}}
        @if (Skv::hasFluxPro())
            <flux:chart />
        @endif
        BLADE;

    expect(FluxProLint::violations($bare))->toBe(['line 1: @if (Skv::hasFluxPro()) has no free @else; mark a feature without a free variant (§6.7) with {{-- pro-only --}}'])
        ->and(FluxProLint::violations($marked))->toBe([]);
});

it('flags an empty free @else (§6.7)', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            <flux:chart />
        @else
            {{-- nothing yet --}}
        @endif
        BLADE;

    expect(FluxProLint::violations($blade))->toBe(['line 1: @if (Skv::hasFluxPro()) has an empty free @else']);
});

it('flags Pro and free branches that bind different Livewire properties (§6.7)', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            <flux:select variant="listbox" multiple wire:model="roleIds">
            </flux:select>
        @else
            <flux:checkbox.group wire:model="roles">
                @if ($showAll)
                    <flux:checkbox wire:model.live="all" />
                @endif
            </flux:checkbox.group>
        @endif
        BLADE;

    expect(FluxProLint::violations($blade))->toBe(['line 1: @if (Skv::hasFluxPro()) binds wire:model [roleIds] in the Pro branch but [all, roles] in the free @else']);
});

it('accepts branches that bind the same properties in any order and with any modifier', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            <flux:date-picker wire:model.live="from" />
            <flux:date-picker wire:model.live="until" />
        @else
            <flux:input type="date"
                wire:model="until" />
            <flux:input type="date" wire:model.live.debounce="from" />
        @endif
        BLADE;

    expect(FluxProLint::violations($blade))->toBe([]);
});

it('reads a Pro select variant over several lines and in either attribute form (G5-10)', function (string $tag, string $violation): void {
    expect(FluxProLint::violations($tag))->toBe([$violation]);
})->with([
    'over several lines' => ["<flux:select\n    variant=\"listbox\"\n    wire:model=\"roles\">", 'line 1: <flux:select variant="listbox" wire:model="roles">'],
    'bound' => ["<flux:select :variant=\"'listbox'\" wire:model=\"roles\">", "line 1: <flux:select :variant=\"'listbox'\" wire:model=\"roles\">"],
    'single quotes' => ["<p>Roles</p>\n<flux:select variant='combobox' wire:model=\"roles\">", "line 2: <flux:select variant='combobox' wire:model=\"roles\">"],
]);

it('ignores directives and tags inside Blade comments (G5-10)', function (): void {
    $blade = <<<'BLADE'
        @if (Skv::hasFluxPro())
            {{-- @if ($legacy) --}}
            <flux:date-picker wire:model="from" />
        @else
            <flux:input type="date" wire:model="until" />
        @endif
        BLADE;

    expect(FluxProLint::violations($blade))->toBe(['line 1: @if (Skv::hasFluxPro()) binds wire:model [from] in the Pro branch but [until] in the free @else'])
        ->and(FluxProLint::violations('{{-- <flux:command /> was the old palette --}}'))->toBe([]);
});

it('accepts a pro-only marker only right before its @if (G5-10)', function (): void {
    $distant = <<<'BLADE'
        {{-- pro-only (§6.7): free Flux has no sign-in trend --}}
        <div>
            <p>Stats</p>
        </div>
        @if (Skv::hasFluxPro())
            <flux:chart />
        @endif
        BLADE;

    expect(FluxProLint::violations($distant))->toBe(['line 5: @if (Skv::hasFluxPro()) has no free @else; mark a feature without a free variant (§6.7) with {{-- pro-only --}}'])
        ->and(FluxProLint::violations("{{-- pro-only (§6.7) --}}\n\n@if (Skv::hasFluxPro())\n<flux:chart />\n@endif"))->toBe(['line 3: @if (Skv::hasFluxPro()) has no free @else; mark a feature without a free variant (§6.7) with {{-- pro-only --}}'])
        ->and(FluxProLint::violations("{{-- pro-only (§6.7) --}} @if (Skv::hasFluxPro())\n<flux:chart />\n@endif"))->toBe([]);
});

it('keeps every view of the kit inside the free Flux surface or a Pro branch (§6.7)', function (): void {
    $violations = [];

    foreach (kitSourceFiles('resources/views') as $path) {
        if (! str_ends_with($path, '.blade.php') || str_starts_with($path, 'resources/views/flux/')) {
            continue;
        }

        foreach (FluxProLint::violations((string) file_get_contents(base_path($path))) as $violation) {
            $violations[] = "{$path} {$violation}";
        }
    }

    expect($violations)->toBe([]);
});
