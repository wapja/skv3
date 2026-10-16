<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Support\ProjectFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->directory = storage_path('framework/testing/setup/project-'.Str::uuid());
    File::ensureDirectoryExists($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('keeps the mode of the file it replaces and gives a new file 0644, atomically (RL-1, g2-07)', function (): void {
    $existing = $this->directory.'/composer.json';
    File::put($existing, '{}');
    chmod($existing, 0640);

    ProjectFile::write($existing, "{\"a\":1}\n");
    ProjectFile::write($this->directory.'/herd.yml', "name: x\n");

    expect(file_get_contents($existing))->toBe("{\"a\":1}\n")
        ->and(fileperms($existing) & 0777)->toBe(0640)
        ->and(fileperms($this->directory.'/herd.yml') & 0777)->toBe(0644)
        ->and(glob($this->directory.'/.state-*'))->toBe([]);
});

it('replaces a symlink instead of writing through it (RL-1, g2-07)', function (): void {
    $outside = $this->directory.'/outside.json';
    File::put($outside, 'keep');
    symlink($outside, $this->directory.'/.mcp.json');

    ProjectFile::write($this->directory.'/.mcp.json', "{}\n");

    expect(file_get_contents($outside))->toBe('keep')
        ->and(is_link($this->directory.'/.mcp.json'))->toBeFalse()
        ->and(file_get_contents($this->directory.'/.mcp.json'))->toBe("{}\n")
        ->and(fileperms($this->directory.'/.mcp.json') & 0777)->toBe(0644);
});
