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

it('creates the app and test databases on PostgreSQL and writes DB_* only after seeding (§5.3.3 step 2)', function () use ($testDatabaseExists): void {
    $context = setupSandbox(prefix: 'setup_probe_');
    $context->answers = Answers::fromArray(['database' => 'pgsql', 'modules' => []], Detection::none(), $context->folderName());
    $name = DatabaseStep::databaseName($context);
    /** @var array{host: string, port: string|int, username: string, password: string} $server */
    $server = config('database.connections.pgsql');
    $admin = new PDO("pgsql:host={$server['host']};port={$server['port']};dbname=postgres", $server['username'], $server['password']);
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
