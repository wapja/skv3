<?php

declare(strict_types=1);

/** covers §5.3.3, §7.3 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\SetupContext;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Tenancy\Hosts;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

$testDatabaseExists = function (PDO $admin, string $database): bool {
    $statement = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
    $statement->execute([$database]);

    return $statement->fetchColumn() !== false;
};

$postgres = function (string $database = 'postgres'): PDO {
    /** @var array{host: string, port: string|int, username: string, password: string} $server */
    $server = config('database.connections.pgsql');

    return new PDO("pgsql:host={$server['host']};port={$server['port']};dbname={$database}", $server['username'], $server['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
};

/** @return list<string> the tables outside PostgreSQL's own schemas */
$tablesOf = function (PDO $database): array {
    $statement = $database->query("SELECT tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY tablename");

    return $statement === false ? [] : array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
};

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));

    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('migrates, syncs and seeds the demo on SQLite, then writes DB_* and .env.testing (§5.3.3 step 2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());

    $result = new DatabaseStep()->run($context);

    expect($result->status)->toBe('done')
        ->and(new EnvFile($context->path('.env'))->get('DB_CONNECTION'))->toBe('sqlite')
        ->and(new EnvFile($context->path('.env'))->get('DB_DATABASE'))->toBeNull()
        ->and(new EnvFile($context->path('.env.testing'))->get('DB_DATABASE'))->toBe(':memory:')
        ->and(new EnvFile($context->path('.env.testing'))->get('APP_ENV'))->toBe('testing')
        ->and(DB::connection('setup')->table('organisations')->whereIn('slug', DemoSeeder::SLUGS)->count())->toBe(2)
        ->and(config('database.default'))->toBe('setup')
        ->and(new DatabaseStep()->isComplete($context))->toBeTrue();
});

it('creates the real super admin instead of demo data when asked (§5.3.3 step 2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray([
        'database' => 'sqlite',
        'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), $context->folderName());

    new DatabaseStep()->run($context);

    $owner = DB::connection('setup')->table('users')->where('email', 'owner@example.com')->sole();

    expect((bool) $owner->is_super_admin)->toBeTrue()
        ->and($owner->organisation_id)->toBeNull()
        ->and(Hash::check('correct-horse-battery', (string) $owner->password))->toBeTrue()
        ->and(DB::connection('setup')->table('organisations')->count())->toBe(0)
        ->and(DB::connection('setup')->table('activity_log')->where('event', 'user.super_admin_granted')->where('subject_id', $owner->id)->count())->toBe(1)
        ->and(new DatabaseStep()->isComplete($context))->toBeTrue();
});

it('is idempotent: a second run keeps one super admin and one grant (§5.3.1)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray([
        'database' => 'sqlite',
        'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), $context->folderName());

    new DatabaseStep()->run($context);
    new DatabaseStep()->run($context);

    expect(DB::connection('setup')->table('users')->where('email', 'owner@example.com')->count())->toBe(1)
        ->and(DB::connection('setup')->table('activity_log')->where('event', 'user.super_admin_granted')->count())->toBe(1);
});

it('refuses to make a pending user the super admin (§5.3.3 step 2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    new DatabaseStep()->run($context);
    $invited = 'invited@demo1.'.Hosts::apex();
    $context->answers = Answers::fromArray([
        'database' => 'sqlite',
        'start_with' => 'super_admin',
        'super_admin_email' => $invited,
        'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), $context->folderName());

    expect(fn (): mixed => new DatabaseStep()->run($context))->toThrow(RuntimeException::class, $invited)
        ->and((bool) DB::connection('setup')->table('users')->where('email', $invited)->value('is_super_admin'))->toBeFalse();
});

it('asks for a password when the super admin of an earlier install is gone (§5.3.3 step 2, DEC-30)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName(), [
        'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com',
    ]);

    expect(fn (): mixed => new DatabaseStep()->run($context))->toThrow(RuntimeException::class, __('The super admin :email does not exist; pass super_admin_password to create it.', ['email' => 'owner@example.com']))
        ->and(DB::connection('setup')->table('users')->where('email', 'owner@example.com')->exists())->toBeFalse()
        ->and(is_file($context->path('.env.testing')))->toBeFalse();
});

it('is not complete before it ran (§5.3.1)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());

    expect(new DatabaseStep()->isComplete($context))->toBeFalse()
        ->and(is_file($context->path('database/database.sqlite')))->toBeFalse();
});

it('refuses a committed PostgreSQL schema dump and leaves .env alone (§7.3, RL-15)', function (): void {
    $context = setupSandbox();
    File::ensureDirectoryExists($context->path('database/schema'));
    File::put($context->path('database/schema/pgsql-schema.sql'), '-- dump');
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $before = File::get($context->path('.env'));

    expect(fn (): mixed => new DatabaseStep()->run($context))->toThrow(RuntimeException::class, 'pgsql-schema')
        ->and(File::get($context->path('.env')))->toBe($before)
        ->and(is_file($context->path('.env.testing')))->toBeFalse();
});

it('keeps the old DB_* when a later part of the switch fails (§5.3.3 step 2)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    $before = File::get($context->path('.env'));
    // A directory where the database file belongs: the sqlite connection cannot open it.
    File::ensureDirectoryExists($context->path('database/database.sqlite'));

    expect(fn (): mixed => new DatabaseStep()->run($context))->toThrow(Exception::class)
        ->and(File::get($context->path('.env')))->toBe($before)
        ->and(is_file($context->path('.env.testing')))->toBeFalse();
});

it('points DB_DATABASE at the SQLite file when .env still names another database (§5.3.3 step 2)', function (string $previous): void {
    $context = setupSandbox();
    new EnvFile($context->path('.env'))->set(['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $previous]);
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());

    new DatabaseStep()->run($context);
    $database = (string) new EnvFile($context->path('.env'))->get('DB_DATABASE');

    // Relative, so SQLiteConnector resolves it through base_path() wherever the project lives.
    expect($database)->toBe('database/database.sqlite')
        ->and(is_file($context->path($database)))->toBeTrue()
        ->and(new EnvFile($context->path('.env.testing'))->get('DB_DATABASE'))->toBe(':memory:')
        ->and(new DatabaseStep()->isComplete($context))->toBeTrue();
})->with(['a PostgreSQL name' => 'other', 'an empty value' => '']);

it('is not complete while .env opens another SQLite database (§5.3.1)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['database' => 'sqlite'], Detection::none(), $context->folderName());
    new DatabaseStep()->run($context);

    new EnvFile($context->path('.env'))->set(['DB_DATABASE' => 'other']);

    expect(new DatabaseStep()->isComplete($context))->toBeFalse();
});

it('refuses a folder name that gives no usable PostgreSQL database name (§5.3.3 step 2)', function (string $folder): void {
    $sandbox = setupSandbox();
    $context = new SetupContext($sandbox->path($folder), $sandbox->answers, $sandbox->detection);
    File::ensureDirectoryExists($context->path('database'));
    File::copy($sandbox->path('.env'), $context->path('.env'));
    $context->answers = Answers::fromArray(['app_name' => 'App', 'database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $before = File::get($context->path('.env'));
    // An unreachable server: the refusal comes before the step connects to anything.
    config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 1]);

    expect(fn (): mixed => new DatabaseStep()->run($context))->toThrow(RuntimeException::class, 'no usable PostgreSQL database name')
        ->and(File::get($context->path('.env')))->toBe($before)
        ->and(is_file($context->path('.env.testing')))->toBeFalse();
})->with(['postgres', 'template0', 'Template1', '--']);

it('folds the folder name into a PostgreSQL database name (§5.3.3 step 2)', function (string $folder, string $expected): void {
    $context = setupSandbox();
    $named = new SetupContext(dirname($context->basePath).'/'.$folder, $context->answers, $context->detection);

    expect(DatabaseStep::databaseName($named))->toBe($expected);
})->with([
    'plain' => ['my-app', 'my_app'],
    'upper case and runs' => ['My  App--2', 'my_app_2'],
    'trimmed' => ['-app-', 'app'],
    'at most 50 characters' => [str_repeat('a', 60), str_repeat('a', 50)],
]);

it('creates the app and test databases on PostgreSQL and writes DB_* only after seeding (§5.3.3 step 2)', function () use ($testDatabaseExists, $postgres): void {
    $context = setupSandbox(prefix: 'setup_probe_');
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $name = DatabaseStep::databaseName($context);
    /** @var array{host: string, port: string|int, username: string, password: string} $server */
    $server = config('database.connections.pgsql');
    $admin = $postgres();
    // Runs after afterEach's purge of the setup connection, so no session holds the databases.
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });

    new DatabaseStep()->run($context);
    $env = new EnvFile($context->path('.env'));

    expect($name)->toStartWith('setup_probe_')
        ->and($env->get('DB_CONNECTION'))->toBe('pgsql')
        ->and($env->get('DB_DATABASE'))->toBe($name)
        ->and($env->get('DB_HOST'))->toBe((string) $server['host'])
        ->and(new EnvFile($context->path('.env.testing'))->get('DB_DATABASE'))->toBe("{$name}_test")
        ->and($testDatabaseExists($admin, "{$name}_test"))->toBeTrue()
        ->and(DB::connection('setup')->table('organisations')->count())->toBe(2)
        ->and(new DatabaseStep()->isComplete($context))->toBeTrue();
})->skip(fn (): bool => config('database.default') !== 'pgsql', 'runs under composer test:pgsql only');

it('refuses an existing PostgreSQL database that holds another project\'s tables (§5.3.3 step 2)', function (string $suffix) use ($testDatabaseExists, $postgres, $tablesOf): void {
    $context = setupSandbox(prefix: 'setup_probe_');
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $name = DatabaseStep::databaseName($context);
    $foreign = $name.$suffix;
    $admin = $postgres();
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });
    $admin->exec('CREATE DATABASE "'.$foreign.'"');
    $postgres($foreign)->exec('CREATE TABLE foreign_things (id integer)');
    $before = File::get($context->path('.env'));

    expect(fn (): mixed => new DatabaseStep()->run($context))->toThrow(RuntimeException::class, "database {$foreign} already exists")
        ->and($tablesOf($postgres($foreign)))->toBe(['foreign_things'])
        ->and($testDatabaseExists($admin, $suffix === '' ? "{$name}_test" : $name))->toBeFalse()
        ->and(File::get($context->path('.env')))->toBe($before)
        ->and(is_file($context->path('.env.testing')))->toBeFalse();
})->with(['the app database' => '', 'the test database' => '_test'])
    ->skip(fn (): bool => config('database.default') !== 'pgsql', 'runs under composer test:pgsql only');

it('reuses an existing PostgreSQL database that is provably this project\'s (§5.3.3 step 2)', function (bool $namedByEnv) use ($postgres, $tablesOf): void {
    $context = setupSandbox(prefix: 'setup_probe_');
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $name = DatabaseStep::databaseName($context);
    $admin = $postgres();
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });
    $admin->exec('CREATE DATABASE "'.$name.'"');

    if ($namedByEnv) {
        // What `laravel new --database=pgsql` leaves behind: .env names the database and the installer migrated it.
        $postgres($name)->exec('CREATE TABLE installer_things (id integer)');
        new EnvFile($context->path('.env'))->set(['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $name]);
    }

    new DatabaseStep()->run($context);

    expect($tablesOf($postgres($name)))->toContain('migrations', 'roles')
        ->and(new DatabaseStep()->isComplete($context))->toBeTrue();
})->with(['named by .env' => true, 'empty' => false])
    ->skip(fn (): bool => config('database.default') !== 'pgsql', 'runs under composer test:pgsql only');

it('resumes on the PostgreSQL databases it created itself after .env lost them (§5.3.1)', function () use ($postgres): void {
    $context = setupSandbox(prefix: 'setup_probe_');
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $name = DatabaseStep::databaseName($context);
    $admin = $postgres();
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });
    $before = File::get($context->path('.env'));
    new DatabaseStep()->run($context);
    // A run that failed after migrating looks the same: filled databases that neither .env file names yet.
    DB::purge('setup');
    File::put($context->path('.env'), $before);
    File::delete($context->path('.env.testing'));

    new DatabaseStep()->run($context);

    expect(new EnvFile($context->path('.env'))->get('DB_DATABASE'))->toBe($name)
        ->and(new DatabaseStep()->isComplete($context))->toBeTrue();
})->skip(fn (): bool => config('database.default') !== 'pgsql', 'runs under composer test:pgsql only');
