<?php

declare(strict_types=1);

/** covers §7.4 */

use App\Setup\SetupContext;
use App\Setup\Steps\DatabaseStep;
use Illuminate\Support\Facades\Process;

it('names the test database like the Database step does (A7, RL-14)', function (string $folder): void {
    $context = setupSandbox();
    $expected = DatabaseStep::databaseName(new SetupContext(dirname($context->basePath).'/'.$folder, $context->answers, $context->detection)).'_test';

    // A closed port and a throwaway user: should --print-name ever stop exiting early, the script fails as
    // "not reachable" instead of creating the database on the developer's PostgreSQL.
    $printed = Process::path(base_path())
        ->env(['PWD' => '/tmp/'.$folder, 'DB_TEST_DATABASE' => false, 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_USERNAME' => 'nobody', 'DB_PASSWORD' => ''])
        ->run([PHP_BINARY, 'scripts/create-pgsql-test-database.php', '--print-name']);

    expect($printed->successful())->toBeTrue()
        ->and($printed->output())->toBe($expected."\n")
        ->and($printed->output())->not->toContain('Created database');
})->with(['plain' => ['myapp3'], 'hyphen' => ['my-app'], 'case and dots' => ['My.App 2']]);
