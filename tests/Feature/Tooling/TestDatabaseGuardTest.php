<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

it('lets the tests run only on a test database (CD-16, A84a)', function (mixed $database, mixed $testDatabase, bool $allowed): void {
    $refusal = TestCase::databaseRefusal($database, '', $testDatabase, '/srv/app');

    expect($refusal === null)->toBe($allowed);
})->with([
    'SQLite in memory' => [':memory:', null, true],
    'a _test database' => ['my_app_test', null, true],
    'a parallel worker of one' => ['my_app_test_test_3', null, true],
    'CI\'s database' => ['app_test', null, true],
    'a file under storage/framework/testing' => ['/srv/app/storage/framework/testing/browser.sqlite', null, true],
    'the DB_TEST_DATABASE test:pgsql was given' => ['my_app_ci', 'my_app_ci', true],
    'the development database' => ['my_app', null, false],
    'the development database, with another DB_TEST_DATABASE' => ['my_app', 'my_app_ci', false],
    'a name that only contains _test' => ['my_app_test_backup', null, false],
    'a name with a trailing newline' => ["my_app_test\n", null, false],
    'the default SQLite file' => ['/srv/app/database/database.sqlite', null, false],
    'a path that climbs out of storage/framework/testing' => ['/srv/app/storage/framework/testing/../../../database/database.sqlite', null, false],
    'another project\'s testing folder' => ['/srv/other/storage/framework/testing/x.sqlite', null, false],
    'no DB_DATABASE' => [null, null, false],
    'an empty DB_DATABASE and DB_TEST_DATABASE' => ['', '', false],
]);

it('refuses a DB_URL, whose database would win over DB_DATABASE (CD-16)', function (): void {
    expect(TestCase::databaseRefusal(':memory:', 'pgsql://root@127.0.0.1/my_app', null, '/srv/app'))->toBeString()->toContain('DB_URL');
});

it('refuses a cached configuration, whose database wins over DB_DATABASE (CD-16)', function (): void {
    $cache = (string) tempnam(sys_get_temp_dir(), 'config-cache');

    try {
        expect(TestCase::databaseRefusal(':memory:', '', null, '/srv/app', $cache))->toBeString()->toContain('cached configuration')
            ->and(TestCase::databaseRefusal(':memory:', '', null, '/srv/app', $cache.'.missing'))->toBeNull();
    } finally {
        unlink($cache);
    }
});

it('stops a run on the development database before the application boots (CD-16, A84a)', function (): void {
    // A real child run, as `composer test` would start it with DB_DATABASE exported. The directory does not exist, so
    // even without the guard nothing could be created; the parallel runner's variables are removed for the child.
    $result = Process::path(base_path())
        ->env([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/nonexistent-directory/database.sqlite',
            'DB_URL' => '',
            'DB_TEST_DATABASE' => false,
            'LARAVEL_PARALLEL_TESTING' => false,
            'PARATEST' => false,
            'TEST_TOKEN' => false,
            'UNIQUE_TEST_TOKEN' => false,
        ])
        ->timeout(120)
        ->run([PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/ExampleTest.php']);

    expect($result->exitCode())->not->toBe(0)
        ->and($result->output().$result->errorOutput())->toContain('Refusing to run the tests on the database');
});

it('stops a run under a configuration cache before the application boots, whatever DB_DATABASE says (CD-16)', function (): void {
    // `php artisan config:cache` (the doctor's fix for a missing backup password) freezes the developer's database
    // into the cache, which Laravel then loads instead of reading DB_DATABASE. The cache here is an empty one in a
    // directory of its own, named through APP_CONFIG_CACHE, so the repository's bootstrap/cache is never touched.
    $directory = storage_path('framework/testing/'.Str::uuid());
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/config.php', "<?php return [];\n");

    try {
        $result = Process::path(base_path())
            ->env([
                'APP_CONFIG_CACHE' => $directory.'/config.php',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => '',
                'DB_TEST_DATABASE' => false,
                'LARAVEL_PARALLEL_TESTING' => false,
                'PARATEST' => false,
                'TEST_TOKEN' => false,
                'UNIQUE_TEST_TOKEN' => false,
            ])
            ->timeout(120)
            ->run([PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/ExampleTest.php']);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($result->exitCode())->not->toBe(0)
        ->and($result->output().$result->errorOutput())->toContain('Refusing to run the tests with a cached configuration');
});
