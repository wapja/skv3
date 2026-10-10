<?php

declare(strict_types=1);

/** covers §6.1, §7.1 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/** @return Collection<int, SplFileInfo> */
function viewFiles(): Collection
{
    return collect(File::allFiles(resource_path('views')));
}

it('has no emoji in any view path', function (): void {
    expect(viewFiles()->filter(fn (SplFileInfo $f): bool => str_contains($f->getRelativePathname(), '⚡')))->toBeEmpty();
});

it('keeps component PHP out of Blade files (multi-file components only)', function (): void {
    $singleFile = viewFiles()
        ->filter(fn (SplFileInfo $f): bool => str_ends_with($f->getFilename(), '.blade.php'))
        ->filter(fn (SplFileInfo $f): bool => preg_match('/new\s+(#\[[^\]]*\]\s*)*class\s+extends\s+Component/', $f->getContents()) === 1)
        ->map(fn (SplFileInfo $f): string => $f->getRelativePathname());

    expect($singleFile->values()->all())->toBe([]);
});

it('never calls $this->view() in component classes', function (): void {
    $offenders = viewFiles()
        ->filter(fn (SplFileInfo $f): bool => str_ends_with($f->getFilename(), '.php') && ! str_ends_with($f->getFilename(), '.blade.php'))
        ->filter(fn (SplFileInfo $f): bool => str_contains($f->getContents(), '$this->view('))
        ->map(fn (SplFileInfo $f): string => $f->getRelativePathname());

    expect($offenders->values()->all())->toBe([]);
});

it('generates multi-file components without emoji', function (): void {
    expect(config('livewire.make_command.type'))->toBe('mfc')
        ->and(config('livewire.make_command.emoji'))->toBeFalse();
});
