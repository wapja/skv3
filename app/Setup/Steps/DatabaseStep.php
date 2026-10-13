<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\User;
use App\Setup\Answers;
use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\EnvFile;
use App\Tenancy\Facades\Tenant;
use Database\Seeders\DemoSeeder;
use Database\Seeders\EssentialsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * §5.3.3 step 2, the atomic switch: create (pgsql) or open (sqlite) the database, point the runtime `setup`
 * connection at it, migrate, sync the permissions, seed the essentials and the chosen start (demo data or the real
 * super admin), and only then write DB_* and .env.testing. Any failure throws, so .env keeps the old DB_*. Later
 * steps in the same process use the `setup` connection, which is the default from here on (RL-6).
 */
final class DatabaseStep implements SetupStep
{
    public const string CONNECTION = 'setup';

    /** The SQLite file as .env names it: relative, so SQLiteConnector resolves it through base_path(). */
    public const string SQLITE_DATABASE = 'database/database.sqlite';

    /** Names a folder must not turn into: the maintenance database and the templates every new database copies. */
    private const array RESERVED_DATABASES = ['postgres', 'template0', 'template1'];

    /** The app database name: the folder name lower-cased, other characters folded to _, at most 50 characters. */
    public static function databaseName(SetupContext $context): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9_]+/', '_', Str::lower($context->folderName())), '_');

        return trim(Str::substr($name, 0, 50), '_');
    }

    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return __('Database');
    }

    public function isComplete(SetupContext $context): bool
    {
        $answers = $context->answers;

        $env = new EnvFile($context->path('.env'));

        if ($env->get('DB_CONNECTION') !== $answers->database || ! is_file($context->path('.env.testing'))) {
            return false;
        }

        // The database the app opens must be the one this step migrated.
        $opens = $env->get('DB_DATABASE');

        if ($answers->database === 'pgsql' ? $opens !== self::databaseName($context) : ! in_array($opens, [null, self::SQLITE_DATABASE, $this->sqlitePath($context)], true)) {
            return false;
        }

        if ($answers->database === 'sqlite' && ! is_file($this->sqlitePath($context))) {
            return false;
        }

        try {
            $this->configureConnection($context);
            $schema = Schema::connection(self::CONNECTION);

            if (! $schema->hasTable('migrations') || ! $schema->hasTable('roles')) {
                return false;
            }

            return $answers->startWith === 'demo'
                ? DB::connection(self::CONNECTION)->table('organisations')->whereIn('slug', DemoSeeder::SLUGS)->count() === count(DemoSeeder::SLUGS)
                : DB::connection(self::CONNECTION)->table('users')->where('email', $answers->superAdminEmail)->where('is_super_admin', true)->exists();
        } catch (Throwable) {
            return false;
        } finally {
            DB::purge(self::CONNECTION);
        }
    }

    public function isLong(): bool
    {
        return false;
    }

    /** @throws RuntimeException when a schema dump is present, or a migration, the sync or a seeder fails */
    public function run(SetupContext $context): StepResult
    {
        $answers = $context->answers;

        if (glob($context->path('database/schema/pgsql-schema.*')) !== []) {
            throw new RuntimeException(__('Remove database/schema/pgsql-schema.* first: setup runs plain migrations (§7.3).'));
        }

        if ($answers->database === 'pgsql') {
            $this->createPostgresDatabases($context);
        } elseif (! file_exists($this->sqlitePath($context))) {
            touch($this->sqlitePath($context));
        }

        $this->configureConnection($context);
        config(['database.default' => self::CONNECTION]);

        $this->artisan('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        $this->artisan('permissions:sync');
        $this->artisan('db:seed', ['--class' => EssentialsSeeder::class, '--force' => true]);

        if ($answers->startWith === 'demo') {
            $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        } else {
            $this->createSuperAdmin($answers);
        }

        $this->writeEnvironment($context);

        return StepResult::done(__('Database ready: :name', ['name' => $answers->database === 'pgsql' ? self::databaseName($context) : 'SQLite']));
    }

    /**
     * Creates the app database and <name>_test on the server of the pgsql connection. The folder name alone names
     * them, so an existing one may belong to another project: it is reused only when it is provably this project's
     * (empty, created by this step for this folder, or already named by .env / .env.testing). Both are checked before
     * anything is created, so a refusal changes nothing.
     *
     * @throws RuntimeException when the name is unusable or an existing database belongs to someone else
     */
    private function createPostgresDatabases(SetupContext $context): void
    {
        $name = self::databaseName($context);

        if ($name === '' || in_array($name, self::RESERVED_DATABASES, true)) {
            throw new RuntimeException(__('The folder name gives no usable PostgreSQL database name (":name"); rename the folder or choose SQLite.', ['name' => $name]));
        }

        $admin = $this->connectToPostgres('postgres');
        $marker = $this->ownershipMarker($context);
        $comment = $admin->prepare("SELECT coalesce(shobj_description(oid, 'pg_database'), '') FROM pg_database WHERE datname = ?");
        $missing = [];

        foreach ([$name => '.env', "{$name}_test" => '.env.testing'] as $database => $envFile) {
            $comment->execute([$database]);
            $description = $comment->fetchColumn();

            if ($description === false) {
                $missing[] = $database;

                continue;
            }

            if ($description !== $marker && ! $this->isNamedBy($context->path($envFile), $database) && ! $this->isEmpty($database)) {
                throw new RuntimeException(__('The PostgreSQL database :name already exists and holds tables of another project; drop or rename it, or rename this folder.', ['name' => $database]));
            }
        }

        foreach ($missing as $database) {
            $admin->exec('CREATE DATABASE '.$this->identifier($database));
            $admin->exec('COMMENT ON DATABASE '.$this->identifier($database).' IS '.$admin->quote($marker));
        }
    }

    /** Marks a database this step created for this folder, so a re-run after a failure can tell it from a stranger's. */
    private function ownershipMarker(SetupContext $context): string
    {
        return 'setup:'.hash('sha256', realpath($context->basePath) ?: $context->basePath);
    }

    private function isNamedBy(string $envFile, string $database): bool
    {
        $env = new EnvFile($envFile);

        return $env->get('DB_CONNECTION') === 'pgsql' && $env->get('DB_DATABASE') === $database;
    }

    /** No tables outside PostgreSQL's own schemas. */
    private function isEmpty(string $database): bool
    {
        $tables = $this->connectToPostgres($database)
            ->query("SELECT count(*) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')");

        return $tables !== false && (int) $tables->fetchColumn() === 0;
    }

    private function connectToPostgres(string $database): PDO
    {
        $server = $this->postgresServer();

        return new PDO("pgsql:host={$server['host']};port={$server['port']};dbname={$database}", $server['username'], $server['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function identifier(string $database): string
    {
        return '"'.str_replace('"', '""', $database).'"';
    }

    /** Points the runtime `setup` connection at the step's database, without creating anything. */
    private function configureConnection(SetupContext $context): void
    {
        $template = $context->answers->database === 'pgsql' ? 'pgsql' : 'sqlite';
        /** @var array<string, mixed> $connection */
        $connection = config("database.connections.{$template}");
        $database = $template === 'pgsql' ? self::databaseName($context) : $this->sqlitePath($context);

        config(['database.connections.'.self::CONNECTION => array_merge($connection, ['url' => null, 'database' => $database])]);
        DB::purge(self::CONNECTION);
    }

    /**
     * The real super admin (§5.3.3 step 2): an existing user is promoted, a new one is inserted with the flag (W8)
     * and completed by handleCreated(), in one transaction, so it never exists without the badge and the grant.
     */
    private function createSuperAdmin(Answers $answers): void
    {
        $email = (string) $answers->superAdminEmail;
        $setSuperAdmin = app(SetSuperAdmin::class);
        $existing = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        if ($existing instanceof User) {
            // SetSuperAdmin guards demotion only: a pending or disabled user could never sign in.
            if ($existing->status !== UserStatus::Active) {
                throw new RuntimeException(__('Only an active user can become a super admin; :email is :status.', ['email' => $email, 'status' => $existing->status->label()]));
            }

            $setSuperAdmin->handle($existing, true);

            return;
        }

        DB::transaction(function () use ($answers, $email, $setSuperAdmin): void {
            $user = new User;
            // The hashed cast keeps a value that is already a hash (Answers hashes the password on entry, RL-5).
            $user->forceFill([
                'organisation_id' => null,
                'is_super_admin' => true,
                'status' => UserStatus::Active,
                'locale' => Locale::default(),
                'name' => Str::headline(Str::before($email, '@')),
                'email' => $email,
                'password' => $answers->superAdminPasswordHash,
            ])->save();

            $setSuperAdmin->handleCreated($user);
        });
    }

    /** The last part of the switch: DB_* into .env, then .env.testing as a copy pointing at the test database. */
    private function writeEnvironment(SetupContext $context): void
    {
        $env = $context->path('.env');
        $testing = $context->path('.env.testing');

        if ($context->answers->database === 'pgsql') {
            $server = $this->postgresServer();
            new EnvFile($env)->set([
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $server['host'],
                'DB_PORT' => $server['port'],
                'DB_DATABASE' => self::databaseName($context),
                'DB_USERNAME' => $server['username'],
                'DB_PASSWORD' => $server['password'],
            ]);
        } else {
            // Unset, DB_DATABASE falls back to database/database.sqlite. A value left by pgsql (or an empty one) would
            // become the SQLite path, so it is pointed back at the file, relative, as Laravel resolves it.
            new EnvFile($env)->set(new EnvFile($env)->get('DB_DATABASE') === null
                ? ['DB_CONNECTION' => 'sqlite']
                : ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => self::SQLITE_DATABASE]);
        }

        if (! copy($env, $testing)) {
            throw new RuntimeException('Could not write .env.testing.');
        }

        chmod($testing, fileperms($env) & 0777);

        new EnvFile($testing)->set([
            'APP_ENV' => 'testing',
            'DB_DATABASE' => $context->answers->database === 'pgsql' ? self::databaseName($context).'_test' : ':memory:',
        ]);
    }

    /** @return array{host: string, port: string, username: string, password: string} the pgsql connection's server */
    private function postgresServer(): array
    {
        /** @var array{host?: mixed, port?: mixed, username?: mixed, password?: mixed} $connection */
        $connection = config('database.connections.pgsql');

        return [
            'host' => $this->stringOf($connection['host'] ?? null),
            'port' => $this->stringOf($connection['port'] ?? null),
            'username' => $this->stringOf($connection['username'] ?? null),
            'password' => $this->stringOf($connection['password'] ?? null),
        ];
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function sqlitePath(SetupContext $context): string
    {
        return $context->path(self::SQLITE_DATABASE);
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws RuntimeException when the command exits non-zero
     */
    private function artisan(string $command, array $parameters = []): void
    {
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException(trim("[{$command}] failed. ".Artisan::output()));
        }
    }
}
