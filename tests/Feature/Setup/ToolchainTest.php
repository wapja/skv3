<?php

declare(strict_types=1);

/** covers §5.3.2, §5.3.4 (DEC-23) */

use App\Setup\Support\EnvFile;
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

it('keeps the project\'s .env values out of child processes (RL-12)', function (): void {
    $context = setupSandbox();
    new EnvFile($context->path('.env'))->set(['DB_PASSWORD' => 'secret', 'APP_KEY' => 'base64:x', 'LANG' => 'nl_NL.UTF-8']);

    $environment = new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid()), $context->basePath)->environment();

    expect($environment['DB_PASSWORD'] ?? null)->toBeFalse()
        ->and($environment['APP_KEY'] ?? null)->toBeFalse()
        ->and($environment)->not->toHaveKey('LANG')
        ->and($environment['HOME'])->toBeString();
});

it('resolves the toolchain from the container with this project as its base path (RL-12)', function (): void {
    app()->forgetInstance(Toolchain::class);

    $toolchain = app(Toolchain::class);

    expect(new ReflectionProperty(Toolchain::class, 'basePath')->getValue($toolchain))->toBe(base_path())
        ->and(new ReflectionProperty(Toolchain::class, 'home')->getValue($toolchain))->toBeNull();
});

it('finds the CLI php next to Herd\'s php-fpm (DEC-23)', function (): void {
    $bin = $this->home.'/Library/Application Support/Herd/bin';
    File::put($bin.'/php', "#!/bin/sh\n");
    chmod($bin.'/php', 0755);

    expect(new Toolchain($this->home)->which('php'))->toBe($bin.'/php')
        ->and(is_executable((string) new Toolchain($this->home)->phpCli()))->toBeTrue();
});

it('scrubs the keys of an .env that Dotenv cannot parse, without throwing (g3-08, RL-12)', function (): void {
    $context = setupSandbox();
    File::put($context->path('.env'), "APP_KEY=base64:x\nDB_PASSWORD=secret\nBROKEN=a b\n");

    $environment = new Toolchain($this->home, $context->basePath)->environment();

    expect($environment['APP_KEY'] ?? null)->toBeFalse()
        ->and($environment['DB_PASSWORD'] ?? null)->toBeFalse()
        ->and($environment['BROKEN'] ?? null)->toBeFalse()
        ->and($environment['HOME'])->toBe($this->home);
});

it('parses an unchanged .env once, and again once it changes (g3-09)', function (): void {
    $context = setupSandbox();
    $path = $context->path('.env');
    File::put($path, "FIRST_KEY=1\n");
    $toolchain = new Toolchain($this->home, $context->basePath);

    $first = $toolchain->environment();
    $mtime = (int) filemtime($path);
    // Same size, inode and mtime: the cached names stand, so this edit is not read yet.
    File::put($path, "OTHER_KEY=1\n");
    touch($path, $mtime);
    $cached = $toolchain->environment();
    touch($path, $mtime + 5);
    $changed = $toolchain->environment();

    expect($first)->toHaveKey('FIRST_KEY')
        ->and($cached)->toHaveKey('FIRST_KEY')->not->toHaveKey('OTHER_KEY')
        ->and($changed)->toHaveKey('OTHER_KEY')->not->toHaveKey('FIRST_KEY');
});

it('maps Herd\'s php-fpm to the CLI php next to it (g4-18, DEC-23)', function (): void {
    $bin = $this->home.'/Library/Application Support/Herd/bin';

    foreach (['php84-fpm', 'php84'] as $name) {
        File::put($bin.'/'.$name, "#!/bin/sh\n");
        chmod($bin.'/'.$name, 0755);
    }

    expect(new Toolchain($this->home)->phpCli(binary: $bin.'/php84-fpm'))->toBe($bin.'/php84')
        ->and(new Toolchain($this->home)->phpCli(binary: $bin.'/php84'))->toBe($bin.'/php84');
});
