<?php

declare(strict_types=1);

/** covers §7.4 */

use App\Setup\SetupContext;
use App\Setup\Steps\DatabaseStep;
use Illuminate\Support\Facades\Process;

it('names the test database like the Database step does (A7, RL-14)', function (string $folder): void {
    $context = setupSandbox();
    $expected = DatabaseStep::databaseName(new SetupContext(dirname($context->basePath).'/'.$folder, $context->answers, $context->detection)).'_test';

    $printed = Process::path(base_path())->env(['PWD' => '/tmp/'.$folder, 'DB_TEST_DATABASE' => false])
        ->run([PHP_BINARY, 'scripts/create-pgsql-test-database.php', '--print-name']);

    expect(trim($printed->output()))->toBe($expected);
})->with(['plain' => ['myapp3'], 'hyphen' => ['my-app'], 'case and dots' => ['My.App 2']]);
