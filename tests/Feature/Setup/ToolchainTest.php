<?php

declare(strict_types=1);

/** covers §5.3.2, §5.3.4 (DEC-23) */

use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());
    app()->instance(Toolchain::class, new Toolchain($this->home));
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/bin');
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v20.19.0/bin');
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin');
    File::ensureDirectoryExists($this->home.'/.local/bin');
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
});

it('builds HOME and PATH for child processes instead of trusting php-fpm (DEC-23)', function (): void {
    $environment = new Toolchain($this->home)->environment();
    $path = explode(':', $environment['PATH']);

    expect($environment['HOME'])->toBe($this->home)
        ->and($path[0])->toBe($this->home.'/Library/Application Support/Herd/bin')
        ->and($path[1])->toBe($this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin')
        ->and($path[2])->toBe($this->home.'/.local/bin')
        ->and($path)->toContain('/usr/bin');
});

it('finds herd by its absolute path and only when it exists (DEC-23)', function (): void {
    expect(new Toolchain($this->home)->herd())->toBeNull();

    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    expect(new Toolchain($this->home)->herd())->toBe($herd);
});

it('looks binaries up on the built PATH without a shell', function (): void {
    $claude = $this->home.'/.local/bin/claude';
    File::put($claude, "#!/bin/sh\n");
    chmod($claude, 0755);

    expect(new Toolchain($this->home)->which('claude'))->toBe($claude)
        ->and(new Toolchain($this->home)->which('definitely-not-installed'))->toBeNull();
});
