<?php

declare(strict_types=1);

/** covers §7.4 */

use Illuminate\Support\Facades\Process;

it('explains an unreachable PostgreSQL instead of throwing (DEC-25)', function (): void {
    $result = Process::path(base_path())->env(['DB_PORT' => '1', 'DB_TEST_DATABASE' => 'unused_test'])->run([PHP_BINARY, 'scripts/create-pgsql-test-database.php']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('PostgreSQL is not reachable')
        ->and($result->errorOutput())->not->toContain('PDOException')
        ->and($result->errorOutput())->not->toContain('Stack trace');
});

it('names the test database after $PWD, like the test:pgsql script (DEC-25)', function (): void {
    expect((string) file_get_contents(base_path('scripts/create-pgsql-test-database.php')))->toContain("getenv('PWD')");
});
