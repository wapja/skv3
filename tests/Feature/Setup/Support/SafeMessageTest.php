<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Support\SafeMessage;
use App\Setup\Support\Toolchain;
use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('shows a query error without its bindings or PostgreSQL detail (RL-13)', function (): void {
    $pdo = new PDOException("SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\"\nDETAIL:  Failing row contains (1, \$2y\$12\$hash).\nHINT: none");
    $exception = new QueryException('pgsql', 'insert into users (password) values (?)', ['$2y$12$hash'], $pdo);

    expect(SafeMessage::of($exception))
        ->toBe('SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "name"')
        ->not->toContain('$2y$');
});

it('shows only the first line of a failed process (RL-13)', function (): void {
    $exception = new ProcessFailedException(new FakeProcessResult(command: 'npm ci', exitCode: 1, output: "line one\nsecret line", errorOutput: 'boom'));

    expect(SafeMessage::of($exception))->toBe('The command "npm ci" failed.')
        ->not->toContain('secret line');
});

it('keeps an ordinary message (RL-13)', function (): void {
    expect(SafeMessage::of(new RuntimeException('Remove the schema dump first.')))->toBe('Remove the schema dump first.');
});
