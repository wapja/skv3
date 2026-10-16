<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Support\Backups\PrivateBackupTemp;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    $this->sandbox = setupSandbox();
    $this->temp = $this->sandbox->path('backup-temp');
    config(['backup.backup.temporary_directory' => $this->temp]);
});

$starting = fn (string $command): CommandStarting => new CommandStarting($command, new ArrayInput([]), new NullOutput);

/** The permission bits of $path, read past PHP's stat cache. */
$mode = function (string $path): int {
    clearstatcache(true, $path);

    return (int) fileperms($path) & 07777;
};

it('creates the temporary directory private to this account before backup:run (CD-10, L-07)', function () use ($starting, $mode): void {
    $listener = new PrivateBackupTemp;
    $listener($starting('backup:run'));

    expect(is_dir($this->temp))->toBeTrue()
        ->and($mode($this->temp))->toBe(0700);
});

it('narrows an existing temporary directory to 0700 (CD-10, L-07)', function () use ($starting, $mode): void {
    File::ensureDirectoryExists($this->temp);
    chmod($this->temp, 0755);

    $listener = new PrivateBackupTemp;
    $listener($starting('backup:run'));

    expect($mode($this->temp))->toBe(0700);
});

it('leaves the directory alone for other commands (CD-10)', function () use ($starting): void {
    $listener = new PrivateBackupTemp;
    $listener($starting('backup:list'));

    expect(is_dir($this->temp))->toBeFalse();
});

it('runs as a CommandStarting listener (CD-10)', function () use ($starting, $mode): void {
    // The other backup:run listeners run too: an encrypted run, a configured pg_dump, no PostgreSQL source (L-05).
    config([
        'backup.backup.password' => 'correct-horse-battery',
        'database.connections.pgsql.dump.dump_binary_path' => $this->sandbox->path('no-pg-dump'),
        'backup.backup.source.databases' => [],
    ]);

    event($starting('backup:run'));

    expect($mode($this->temp))->toBe(0700);
});

it('removes the files a killed run left behind, only those older than six hours and only under temp/ (CD-10, F18)', function (): void {
    File::ensureDirectoryExists($this->temp.'/temp/db-dumps');
    File::put($this->temp.'/temp/db-dumps/database.sql', 'plaintext');
    touch($this->temp.'/temp/db-dumps/database.sql', now()->subHours(7)->getTimestamp());
    File::put($this->temp.'/temp/manifest.txt', 'a running backup');
    touch($this->temp.'/temp/manifest.txt', now()->subHours(5)->getTimestamp());
    // The package writes only under temp/: a file beside it is not a backup's, however old.
    File::put($this->temp.'/keep.txt', 'not a backup file');
    touch($this->temp.'/keep.txt', now()->subHours(7)->getTimestamp());

    expect(new PrivateBackupTemp()->removeStale())->toBe(1)
        ->and(is_file($this->temp.'/temp/db-dumps/database.sql'))->toBeFalse()
        ->and(is_file($this->temp.'/temp/manifest.txt'))->toBeTrue()
        ->and(is_file($this->temp.'/keep.txt'))->toBeTrue();
});

it('never changes or empties a shared sticky directory (CD-10)', function () use ($starting, $mode): void {
    File::ensureDirectoryExists($this->temp.'/temp');
    chmod($this->temp, 01777);
    File::put($this->temp.'/temp/someone-else.sql', 'theirs');
    touch($this->temp.'/temp/someone-else.sql', now()->subDay()->getTimestamp());

    $listener = new PrivateBackupTemp;
    $listener($starting('backup:run'));

    expect($mode($this->temp))->toBe(01777)
        ->and(new PrivateBackupTemp()->removeStale())->toBe(0)
        ->and(is_file($this->temp.'/temp/someone-else.sql'))->toBeTrue();
});

it('never follows a temp/ that is a symbolic link (CD-10)', function () use ($mode): void {
    // Someone who could write the temporary directory once planted temp/ -> a directory of the application.
    $victim = $this->sandbox->path('victim');
    File::ensureDirectoryExists($victim.'/nested');
    File::put($victim.'/.env', 'APP_KEY=secret');
    File::put($victim.'/nested/code.php', '<?php');
    touch($victim.'/.env', now()->subDay()->getTimestamp());
    touch($victim.'/nested/code.php', now()->subDay()->getTimestamp());
    File::ensureDirectoryExists($this->temp);
    chmod($this->temp, 0700);
    symlink($victim, $this->temp.'/temp');

    expect(new PrivateBackupTemp()->removeStale())->toBe(0)
        ->and(is_file($victim.'/.env'))->toBeTrue()
        ->and(is_file($victim.'/nested/code.php'))->toBeTrue()
        ->and($mode($this->temp))->toBe(0700);
});

it('never changes or empties a temporary directory that is a symbolic link (CD-10)', function () use ($starting, $mode): void {
    $victim = $this->sandbox->path('victim');
    File::ensureDirectoryExists($victim.'/temp');
    chmod($victim, 0755);
    File::put($victim.'/temp/database.sql', 'theirs');
    touch($victim.'/temp/database.sql', now()->subDay()->getTimestamp());
    symlink($victim, $this->temp);

    $listener = new PrivateBackupTemp;
    $listener($starting('backup:run'));

    expect($mode($victim))->toBe(0755)
        ->and(new PrivateBackupTemp()->removeStale())->toBe(0)
        ->and(is_file($victim.'/temp/database.sql'))->toBeTrue();
});

it('narrows a group-writable temporary directory before it cleans temp/ (CD-10)', function () use ($mode): void {
    File::ensureDirectoryExists($this->temp.'/temp');
    chmod($this->temp, 0775);
    File::put($this->temp.'/temp/database.sql', 'plaintext');
    touch($this->temp.'/temp/database.sql', now()->subHours(7)->getTimestamp());

    expect(new PrivateBackupTemp()->removeStale())->toBe(1)
        ->and($mode($this->temp))->toBe(0700)
        ->and(is_file($this->temp.'/temp/database.sql'))->toBeFalse();
});

it('never follows a temp/ planted while the temporary directory was group-writable (CD-10)', function () use ($mode): void {
    $victim = $this->sandbox->path('victim');
    File::ensureDirectoryExists($victim);
    File::put($victim.'/.env', 'APP_KEY=secret');
    touch($victim.'/.env', now()->subDay()->getTimestamp());
    File::ensureDirectoryExists($this->temp);
    chmod($this->temp, 0775);
    symlink($victim, $this->temp.'/temp');

    expect(new PrivateBackupTemp()->removeStale())->toBe(0)
        ->and(is_file($victim.'/.env'))->toBeTrue()
        ->and($mode($this->temp))->toBe(0700);
});

it('never creates the target of a dangling temporary directory link (CD-10)', function (): void {
    $target = $this->sandbox->path('elsewhere');
    symlink($target, $this->temp);

    new PrivateBackupTemp()->secure();

    expect(file_exists($target))->toBeFalse()
        ->and(is_link($this->temp))->toBeTrue();
});
