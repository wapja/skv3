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
