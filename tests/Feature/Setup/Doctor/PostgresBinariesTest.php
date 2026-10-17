<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Setup\Detection;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Doctor\PostgresBinaries;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Support\ProcessAccount;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    $this->home = $this->sandbox->path('home');
    app()->instance(Toolchain::class, new Toolchain($this->home));
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->home), Detection::none(), null);
    // As /Users/Shared/Herd and /Users/Shared/Herd/services/postgresql: the root is two levels above the services (CD-6).
    $this->root = $this->sandbox->path('herd');
    $this->services = $this->root.'/services/postgresql';
    config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => null]);
});

/** An executable stub at $path; returns the path. */
$stub = function (string $path): string {
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "#!/bin/sh\nexit 0\n");
    chmod($path, 0755);

    return $path;
};

it('prefers Herd\'s newest service over the PATH only while the server is on this machine (CD-6, F5)', function (string $host, ?string $url, bool $herdFirst) use ($stub): void {
    config(['database.connections.pgsql.host' => $host, 'database.connections.pgsql.url' => $url]);
    // ~/.local/bin is on the toolchain PATH before the system directories, so this is the PATH's pg_dump.
    $onPath = $stub($this->home.'/.local/bin/pg_dump');
    $herd = $stub($this->services.'/18/bin/pg_dump');

    expect(new PostgresBinaries($this->services)->find($this->context, 'pg_dump'))->toBe($herdFirst ? $herd : $onPath);
})->with([
    '127.0.0.1' => ['127.0.0.1', null, true],
    'localhost' => ['localhost', null, true],
    '::1' => ['::1', null, true],
    'a remote server' => ['db.example.com', null, false],
    'DB_URL on this machine' => ['db.example.com', 'pgsql://app:secret@[::1]:5432/app', true],
    'DB_URL on a remote server' => ['127.0.0.1', 'pgsql://app:secret@db.example.com:5432/app', false],
]);

it('still prefers PG_DUMP_BINARY_PATH over Herd and the PATH (CD-6)', function () use ($stub): void {
    $configured = $stub($this->sandbox->path('pg/bin/pg_dump'));
    $stub($this->home.'/.local/bin/pg_dump');
    $stub($this->services.'/18/bin/pg_dump');
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => dirname($configured)]);

    expect(new PostgresBinaries($this->services)->find($this->context, 'pg_dump'))->toBe($configured);
});

it('takes the newest Herd service by version, not by name (L-02)', function () use ($stub): void {
    foreach (['9.6', '10', '18'] as $version) {
        $stub($this->services.'/'.$version.'/bin/pg_dump');
    }

    expect(new PostgresBinaries($this->services)->find($this->context, 'pg_dump'))->toBe($this->services.'/18/bin/pg_dump');
});

it('ignores a Herd binary that another account could replace (CD-6, F13)', function (string $writable, int $mode) use ($stub): void {
    $herd = $stub($this->services.'/18/bin/pg_dump');
    $binaries = new PostgresBinaries($this->services);
    $trusted = $binaries->find($this->context, 'pg_dump');

    chmod($writable === '' ? $this->root : $this->root.'/'.$writable, $mode);
    $found = $binaries->find($this->context, 'pg_dump');

    // A pg_dump on the system PATH may still be found; never the writable one under Herd's root.
    expect($trusted)->toBe($herd)
        ->and(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
})->with([
    'the binary, world-writable' => ['services/postgresql/18/bin/pg_dump', 0777],
    'its version directory, world-writable' => ['services/postgresql/18', 0777],
    'its version directory, group-writable' => ['services/postgresql/18', 0775],
    'the services directory, world-writable' => ['services', 0777],
    'Herd\'s root, world-writable' => ['', 0777],
]);

it('ignores a Herd binary owned by another account (CD-6, F13)', function () use ($stub): void {
    $stub($this->services.'/18/bin/pg_dump');

    $found = new PostgresBinaries($this->services, posix_geteuid() + 1)->find($this->context, 'pg_dump');

    expect(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
})->skip(fn (): bool => posix_geteuid() === 0, 'Running as root: root may own every candidate.');

it('ignores a Herd binary that links outside Herd\'s root (CD-6)', function () use ($stub): void {
    $outside = $stub($this->sandbox->path('elsewhere/pg_dump'));
    File::ensureDirectoryExists($this->services.'/18/bin');
    symlink($outside, $this->services.'/18/bin/pg_dump');

    $found = new PostgresBinaries($this->services)->find($this->context, 'pg_dump');

    expect(str_starts_with((string) $found, $this->root.'/'))->toBeFalse()
        ->and($found)->not->toBe($outside);
});

it('ignores a Herd version that is a symlink, even into a trusted directory (CD-6)', function () use ($stub): void {
    // Another account that can write the services directory plants 99 -> a trusted directory, then retargets it.
    $stub($this->root.'/services/trusted/18/bin/pg_dump');
    File::ensureDirectoryExists($this->services);
    chmod($this->services, 0777);
    symlink($this->root.'/services/trusted/18', $this->services.'/99');

    $found = new PostgresBinaries($this->services)->find($this->context, 'pg_dump');

    expect(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
});

it('ignores Herd\'s root when it is a symlink, even into a trusted directory (CD-6)', function () use ($stub): void {
    // As a /Users/Shared/Herd link planted in the world-writable /Users/Shared.
    $stub($this->sandbox->path('real-herd/services/postgresql/18/bin/pg_dump'));
    symlink($this->sandbox->path('real-herd'), $this->root);

    $found = new PostgresBinaries($this->services)->find($this->context, 'pg_dump');

    expect(str_starts_with((string) $found, $this->sandbox->path('real-herd').'/'))->toBeFalse()
        ->and(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
});

it('returns the resolved path of a Herd binary, so no link above Herd\'s root can swap it later (CD-6)', function () use ($stub): void {
    $real = $stub($this->sandbox->path('actual/herd/services/postgresql/18/bin/pg_dump'));
    symlink($this->sandbox->path('actual'), $this->sandbox->path('alias'));

    $found = new PostgresBinaries($this->sandbox->path('alias/herd/services/postgresql'))->find($this->context, 'pg_dump');

    expect($found)->toBe(realpath($real));
});

it('trusts only root-owned Herd candidates without ext-posix, where this account is unknown (CD-9, L7b-51)', function () use ($stub): void {
    app()->instance(ProcessAccount::class, new ProcessAccount(fn (): ?int => null));
    $stub($this->services.'/18/bin/pg_dump');

    $found = new PostgresBinaries($this->services)->find($this->context, 'pg_dump');

    // The sandbox belongs to this account, not root: a PATH pg_dump may still be found, never the Herd one.
    expect(str_starts_with((string) $found, $this->root.'/'))->toBeFalse();
})->skip(fn (): bool => posix_geteuid() === 0, 'Running as root: root owns every candidate.');
