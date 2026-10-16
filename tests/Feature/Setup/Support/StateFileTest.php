<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Support\StateFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->directory = storage_path('framework/testing/setup/state-'.Str::uuid());
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('writes JSON atomically with mode 0600 and reads it back (RL-5)', function (): void {
    $path = $this->directory.'/nested/state.json';

    StateFile::writeJson($path, ['a' => 1]);

    expect(StateFile::readJson($path))->toBe(['a' => 1])
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(fileperms(dirname($path)) & 0777)->toBe(0700)
        ->and(glob(dirname($path).'/.state-*'))->toBe([]);
});

it('reads null for a missing file and refuses JSON that is not an object (RL-5)', function (string $contents): void {
    $path = $this->directory.'/state.json';
    File::ensureDirectoryExists($this->directory);

    expect(StateFile::readJson($path))->toBeNull();

    file_put_contents($path, $contents);

    expect(fn (): ?array => StateFile::readJson($path))->toThrow(JsonException::class);
})->with(['truncated' => ['{"a":'], 'null' => ['null'], 'list' => ['[1,2]'], 'string' => ['"x"']]);

it('replaces a symlink instead of writing through it (RL-5)', function (): void {
    File::ensureDirectoryExists($this->directory);
    $target = $this->directory.'/elsewhere.json';
    file_put_contents($target, 'keep');
    symlink($target, $this->directory.'/state.json');

    StateFile::writeJson($this->directory.'/state.json', ['a' => 1]);

    expect(file_get_contents($target))->toBe('keep')
        ->and(is_link($this->directory.'/state.json'))->toBeFalse();
});

it('removes its temporary copy and throws its own message when the rename fails (RL-5)', function (): void {
    // A directory where the file goes: rename() refuses it once the copy already holds the whole contents.
    $path = $this->directory.'/.env.testing';
    File::ensureDirectoryExists($path);

    expect(fn () => StateFile::write($path, "APP_KEY=secret\n"))
        ->toThrow(RuntimeException::class, __('Could not write :file.', ['file' => '.env.testing']))
        ->and(glob($this->directory.'/.state-*'))->toBe([]);
});

it('writes with the mode it is given (RL-1)', function (): void {
    $path = $this->directory.'/herd.yml';

    StateFile::write($path, "name: x\n", mode: 0644);

    expect(file_get_contents($path))->toBe("name: x\n")
        ->and(fileperms($path) & 0777)->toBe(0644);
});

it('refuses to read a symlinked state file (RL-2, g3-07)', function (): void {
    File::ensureDirectoryExists($this->directory);
    $target = $this->directory.'/elsewhere.json';
    file_put_contents($target, '{"a":1}');
    symlink($target, $this->directory.'/state.json');

    expect(fn (): ?array => StateFile::readJson($this->directory.'/state.json'))
        ->toThrow(JsonException::class, __(':file is a symbolic link.', ['file' => 'state.json']));
});

it('refuses a state file it cannot read like a damaged one (RL-2, L-09)', function (): void {
    File::ensureDirectoryExists($this->directory);
    $path = $this->directory.'/state.json';
    file_put_contents($path, '{"a":1}');
    chmod($path, 0000);

    expect(fn (): ?array => StateFile::readJson($path))
        ->toThrow(JsonException::class, __(':file is not readable.', ['file' => 'state.json']));
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');
