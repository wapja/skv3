<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\EssentialsSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use RuntimeException;

#[Seeder(EssentialsSeeder::class)]
abstract class TestCase extends BaseTestCase
{
    /**
     * CD-16, A84a: phpunit.xml sets DB_DATABASE without `force`, because `composer test:pgsql` and CI pass their own, so
     * a DB_DATABASE exported in the shell wins. The check runs before parent::setUp(), which boots the application and
     * lets RefreshDatabase wipe the database it names. A configuration cache wins over the environment, so it is
     * refused too; its path is resolved as Application::getCachedConfigPath() resolves it.
     *
     * Every test starts a new request cycle, as a php-fpm worker does: Livewire keeps the `@assets` it rendered (the
     * passkey script) and other per-request state in static properties, which would otherwise leak into the HTML of
     * the next test in the same process.
     */
    protected function setUp(): void
    {
        $basePath = dirname(__DIR__);
        $configCache = Env::get('APP_CONFIG_CACHE');
        $configCache = is_string($configCache) && $configCache !== ''
            ? (str_starts_with($configCache, '/') || str_starts_with($configCache, '\\') ? $configCache : $basePath.'/'.$configCache)
            : $basePath.'/bootstrap/cache/config.php';

        $refusal = self::databaseRefusal(Env::get('DB_DATABASE'), Env::get('DB_URL'), Env::get('DB_TEST_DATABASE'), $basePath, $configCache);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        parent::setUp();

        Livewire::flushState();
    }

    /**
     * Why the tests must not run on this database, or null when they may (CD-16): DB_DATABASE is `:memory:`, a name
     * ending in `_test` (a parallel worker's `_test_test_<n>` too), a file under storage/framework/testing, or the
     * DB_TEST_DATABASE that `composer test:pgsql` was given. A configuration cache is refused first: Laravel then never
     * reads DB_DATABASE, so its database is the one the cache was built with. A DB_URL is refused: its database would
     * win over DB_DATABASE.
     */
    public static function databaseRefusal(mixed $database, mixed $url, mixed $testDatabase, string $basePath, ?string $configCache = null): ?string
    {
        if ($configCache !== null && is_file($configCache)) {
            return 'Refusing to run the tests with a cached configuration: its database wins over DB_DATABASE. Run php artisan config:clear (CD-16).';
        }

        if (is_string($url) && $url !== '') {
            return 'Refusing to run the tests with DB_URL set: unset it and name a test database in DB_DATABASE (CD-16).';
        }

        $name = is_string($database) ? $database : '';
        $testing = rtrim($basePath, '/').'/storage/framework/testing/';

        $safe = $name === ':memory:'
            || preg_match('/_test(_test_\d+)?\z/', $name) === 1
            || (str_starts_with($name, $testing) && ! in_array('..', explode('/', $name), true))
            || (is_string($testDatabase) && $testDatabase !== '' && $name === $testDatabase);

        return $safe ? null : sprintf(
            'Refusing to run the tests on the database [%s]: DB_DATABASE must be :memory:, end in _test, lie under storage/framework/testing or equal DB_TEST_DATABASE (CD-16).',
            $name,
        );
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
