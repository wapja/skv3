<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Enums\CheckStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Detection;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\Checks\ApexCheck;
use App\Setup\Doctor\Checks\AppKeyCheck;
use App\Setup\Doctor\Checks\AssetsCheck;
use App\Setup\Doctor\Checks\AuthenticateSessionCheck;
use App\Setup\Doctor\Checks\BackupPasswordCheck;
use App\Setup\Doctor\Checks\ClaudeFilesCheck;
use App\Setup\Doctor\Checks\DatabaseCheck;
use App\Setup\Doctor\Checks\FluxProCredentialsCheck;
use App\Setup\Doctor\Checks\GdCheck;
use App\Setup\Doctor\Checks\GitHooksCheck;
use App\Setup\Doctor\Checks\HealthCheckKeyCheck;
use App\Setup\Doctor\Checks\HerdCheck;
use App\Setup\Doctor\Checks\HerdProCheck;
use App\Setup\Doctor\Checks\HerdSecuredCheck;
use App\Setup\Doctor\Checks\HerdSiteCheck;
use App\Setup\Doctor\Checks\MailCheck;
use App\Setup\Doctor\Checks\MarkerCheck;
use App\Setup\Doctor\Checks\McpCheck;
use App\Setup\Doctor\Checks\MigrationsCheck;
use App\Setup\Doctor\Checks\NodeCheck;
use App\Setup\Doctor\Checks\OrganisationAdminRoleCheck;
use App\Setup\Doctor\Checks\PermissionsCheck;
use App\Setup\Doctor\Checks\PgDumpCheck;
use App\Setup\Doctor\Checks\PhpCheck;
use App\Setup\Doctor\Checks\PlaywrightCheck;
use App\Setup\Doctor\Checks\QueueCheck;
use App\Setup\Doctor\Checks\SchemaToolsCheck;
use App\Setup\Doctor\Checks\SessionDriverCheck;
use App\Setup\Doctor\Checks\SetupTokenCheck;
use App\Setup\Doctor\Checks\StorageLinkCheck;
use App\Setup\Doctor\Checks\SubdomainsCheck;
use App\Setup\Doctor\Checks\SuperAdminBadgesCheck;
use App\Setup\Doctor\Checks\SuperAdminCheck;
use App\Setup\Doctor\Checks\SuperpowersCheck;
use App\Setup\Doctor\Checks\TestDatabaseCheck;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Marker;
use App\Setup\SetupToken;
use App\Setup\Steps\DoctorStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthCheckKey;
use App\Support\Skv;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Fixtures\FixedStatusCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    $this->home = $this->sandbox->path('home');
    app()->instance(Toolchain::class, new Toolchain($this->home));
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->home), Detection::none(), null);
    $this->herd = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $this->defaultConnection = config('database.default');
});

afterEach(function (): void {
    Skv::useFluxPro(null);
    config(['database.default' => $this->defaultConnection]);
    DB::purge('doctor_unreachable');
});

/** An executable stub in the sandbox home, so no check ever finds a real binary first. */
$stub = function (string $path): void {
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "#!/bin/sh\n");
    chmod($path, 0755);
};

/**
 * A context on the same sandbox with a marker holding $answers, and optionally another detection.
 *
 * @param  array<string, mixed>  $answers
 */
$withMarker = fn (DoctorContext $context, array $answers, ?Detection $detection = null): DoctorContext => new DoctorContext(
    $context->basePath,
    $context->toolchain,
    $detection ?? $context->detection,
    ['kit_version' => 'dev', 'answers' => $answers, 'start_with' => 'demo', 'installed_at' => '2026-10-13T00:00:00+00:00', 'summary' => []],
);

$unreachable = function (): void {
    config([
        'database.connections.doctor_unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/'.Str::uuid().'/database.sqlite', 'prefix' => ''],
        'database.default' => 'doctor_unreachable',
    ]);
};

it('passes on a supported PHP and fails on an older one (§5.4)', function (): void {
    $old = new PhpCheck('8.3.12')->run($this->context);

    expect(new PhpCheck()->run($this->context)->status)->toBe(CheckStatus::Pass)
        ->and($old->status)->toBe(CheckStatus::Fail)
        ->and($old->fix)->toBe('herd isolate 8.4');
});

it('needs the gd extension (§5.4)', function (): void {
    $missing = new GdCheck(loaded: false)->run($this->context);

    expect(new GdCheck(loaded: true)->run($this->context)->status)->toBe(CheckStatus::Pass)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe(__('Install the gd extension'));
});

it('fails without an APP_KEY and names the command (§5.4)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['APP_KEY' => '']);

    $result = new AppKeyCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)->and($result->fix)->toBe('php artisan key:generate');

    new EnvFile($this->sandbox->path('.env'))->set(['APP_KEY' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(new AppKeyCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);
});

it('reports the install marker: skipped before setup, failed when unreadable (A8)', function (): void {
    $file = $this->sandbox->path(Marker::FILE);

    $before = new MarkerCheck()->run($this->context);

    File::put($file, '{"answers":');
    $broken = new MarkerCheck()->run($this->context);

    File::put($file, 'null');
    $notAnObject = new MarkerCheck()->run($this->context);

    Marker::write($this->sandbox, ['urls' => [], 'logins' => [], 'manual' => [], 'notes' => []]);
    $installed = new MarkerCheck()->run($this->context);

    expect($before->status)->toBe(CheckStatus::Skipped)
        ->and($broken->status)->toBe(CheckStatus::Fail)
        ->and($broken->message)->toContain(Marker::FILE)
        ->and($notAnObject->status)->toBe(CheckStatus::Fail)
        ->and($installed->status)->toBe(CheckStatus::Pass);
});

it('only warns without Herd (§5.4)', function (): void {
    $absent = new HerdCheck()->run($this->context);
    $present = new HerdCheck()->run(new DoctorContext($this->context->basePath, $this->context->toolchain, $this->herd, null));

    expect($absent->status)->toBe(CheckStatus::Warn)
        ->and($absent->fix)->toBe('https://herd.laravel.com')
        ->and($present->status)->toBe(CheckStatus::Pass);
});

it('only warns without Herd Pro, and skips without Herd (§5.4)', function (): void {
    $pro = new Detection(herd: true, herdPro: true, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');

    expect(new HerdProCheck()->run($this->context)->status)->toBe(CheckStatus::Skipped)
        ->and(new HerdProCheck()->run(new DoctorContext($this->context->basePath, $this->context->toolchain, $this->herd, null))->status)->toBe(CheckStatus::Warn)
        ->and(new HerdProCheck()->run(new DoctorContext($this->context->basePath, $this->context->toolchain, $pro, null))->status)->toBe(CheckStatus::Pass);
});

it('checks that Herd serves the site, parked or linked (§5.4)', function () use ($stub): void {
    $stub($this->home.'/Library/Application Support/Herd/bin/herd');
    $context = new DoctorContext($this->context->basePath, $this->context->toolchain, $this->herd, null);
    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::sequence()
            ->push(Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)))
            ->push(Process::result(json_encode(['/somewhere/else'], JSON_THROW_ON_ERROR))),
    ]);
    $name = basename($context->basePath);

    $served = new HerdSiteCheck()->run($context);
    $unserved = new HerdSiteCheck()->run($context);

    expect(new HerdSiteCheck()->run($this->context)->status)->toBe(CheckStatus::Skipped)
        ->and($served->status)->toBe(CheckStatus::Pass)
        ->and($unserved->status)->toBe(CheckStatus::Fail)
        ->and($unserved->fix)->toBe("herd link {$name}");
});

it('checks the certificate only when HTTPS was chosen (§5.4)', function () use ($stub, $withMarker): void {
    $stub($this->home.'/Library/Application Support/Herd/bin/herd');
    $name = basename($this->context->basePath);
    $secure = $withMarker($this->context, ['https' => true], $this->herd);
    Process::fake([
        "*herd' 'secured'*" => Process::sequence()
            ->push(Process::result("| Site | Expires |\n| {$name}.test | 2027-01-01 |\n"))
            ->push(Process::result('')),
    ]);

    $secured = new HerdSecuredCheck()->run($secure);
    $unsecured = new HerdSecuredCheck()->run($secure);

    expect(new HerdSecuredCheck()->run($withMarker($this->context, ['https' => false], $this->herd))->status)->toBe(CheckStatus::Skipped)
        ->and(new HerdSecuredCheck()->run($withMarker($this->context, ['https' => true]))->status)->toBe(CheckStatus::Skipped)
        ->and($secured->status)->toBe(CheckStatus::Pass)
        ->and($unsecured->status)->toBe(CheckStatus::Fail)
        ->and($unsecured->fix)->toBe("herd secure {$name}");
});

it('quotes a folder name with a space in the Herd fix commands (RL-6, g2-05)', function () use ($stub, $withMarker): void {
    $stub($this->home.'/Library/Application Support/Herd/bin/herd');
    $spaced = setupSandbox(prefix: 'my app ');
    $name = $spaced->folderName();
    $context = $withMarker(new DoctorContext($spaced->basePath, $this->context->toolchain, $this->herd, null), ['https' => true]);
    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else'], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result(''),
    ]);

    expect(new HerdSiteCheck()->run($context)->fix)->toBe("herd link '{$name}'")
        ->and(new HerdSecuredCheck()->run($context)->fix)->toBe("herd secure '{$name}'");
});

it('prints the /etc/hosts lines when subdomains do not resolve without Herd (§5.4)', function (): void {
    Organisation::factory()->create(['slug' => 'acme']);
    config(['tenancy.apex_domain' => 'unresolvable.invalid']);

    $result = new SubdomainsCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->fix)->toContain('127.0.0.1 unresolvable.invalid')
        ->and($result->fix)->toContain('127.0.0.1 admin.unresolvable.invalid')
        ->and($result->fix)->toContain('127.0.0.1 acme.unresolvable.invalid');
});

it('passes when the probe subdomain resolves (§5.4)', function (): void {
    config(['tenancy.apex_domain' => 'my-app.test']);
    $asked = [];

    $result = new SubdomainsCheck(function (string $host) use (&$asked): string {
        $asked[] = $host;

        return '127.0.0.1';
    })->run($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)->and($asked)->toBe(['probe.my-app.test']);
});

it('passes an apex under localhost even when the resolver does not know it, because RFC 6761 makes it loopback (§8)', function (string $apex): void {
    config(['tenancy.apex_domain' => $apex]);

    $result = new SubdomainsCheck(fn (string $host): string => $host)->run($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->message)->toBe(__(':host is a loopback name (RFC 6761)', ['host' => 'probe.'.$apex]));
})->with(['localhost', 'my-app.localhost']);

it('still fails an apex that only ends in the letters localhost (§5.4)', function (): void {
    config(['tenancy.apex_domain' => 'notlocalhost']);

    $result = new SubdomainsCheck(fn (string $host): string => $host)->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail);
});

it('needs the apex to match the APP_URL host (§5.4)', function (string $apex, CheckStatus $status): void {
    new EnvFile($this->sandbox->path('.env'))->set(['APP_URL' => 'http://my-app.test', 'TENANCY_APEX_DOMAIN' => $apex]);

    $result = new ApexCheck()->run($this->context);

    expect($result->status)->toBe($status)
        ->and($result->fix)->toBe($status === CheckStatus::Fail ? __('Set TENANCY_APEX_DOMAIN to the APP_URL host') : null);
})->with([
    'empty' => ['', CheckStatus::Pass],
    'equal' => ['my-app.test', CheckStatus::Pass],
    'different' => ['other.test', CheckStatus::Fail],
]);

it('reports whether the database is reachable (§5.4)', function () use ($unreachable): void {
    expect(new DatabaseCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);

    $unreachable();
    $this->context->forgetSync();
    $result = new DatabaseCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)->and($result->fix)->toBe(__('Check DB_* in .env'));
});

it('skips every database check when the database is not reachable (§5.4)', function (Check $check) use ($unreachable): void {
    $unreachable();

    expect($check->run($this->context))->toEqual(CheckResult::skipped(__('The database is not reachable')));
})->with([
    'migrations' => fn (): Check => new MigrationsCheck,
    'permissions' => fn (): Check => new PermissionsCheck,
    'organisation-admin-role' => fn (): Check => new OrganisationAdminRoleCheck,
    'super-admin' => fn (): Check => new SuperAdminCheck,
    'super-admin-badges' => fn (): Check => new SuperAdminBadgesCheck,
]);

it('reports pending migrations (§5.4)', function (): void {
    expect(new MigrationsCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);

    DB::table('migrations')->where('migration', DB::table('migrations')->max('migration'))->delete();
    $result = new MigrationsCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)->and($result->fix)->toBe('php artisan migrate');
});

it('skips the test database check unless the project uses PostgreSQL (§5.4)', function (): void {
    expect(new TestDatabaseCheck()->run($this->context)->status)->toBe(CheckStatus::Skipped);
});

it('finds the PostgreSQL test database, or names the command that creates it (§5.4)', function (): void {
    $existing = (string) DB::table('pg_database')->where('datname', 'like', '%\_test')->value('datname');
    new EnvFile($this->sandbox->path('.env'))->set(['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => Str::beforeLast($existing, '_test')]);
    $present = new TestDatabaseCheck()->run($this->context);

    new EnvFile($this->sandbox->path('.env'))->set(['DB_DATABASE' => 'setup_missing_'.Str::lower(Str::random(8))]);
    $missing = new TestDatabaseCheck()->run($this->context);

    expect($present->status)->toBe(CheckStatus::Pass)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe('composer test:pgsql');
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Runs under composer test:pgsql only.');

it('warns when a schema dump exists and the PostgreSQL tools are missing (§5.4, §7.3)', function () use ($stub): void {
    // Herd's layout: the trust rule walks up to the root two levels above the services (CD-6).
    $herdServices = $this->sandbox->path('herd/services/postgresql');
    $check = new SchemaToolsCheck($herdServices);

    expect($check->run($this->context)->status)->toBe(CheckStatus::Skipped);

    File::ensureDirectoryExists($this->sandbox->path('database/schema'));
    File::put($this->sandbox->path('database/schema/pgsql-schema.sql'), '');
    $missing = $check->run($this->context);

    $stub($herdServices.'/18/bin/psql');
    $stub($herdServices.'/18/bin/pg_dump');

    expect($missing->status)->toBe(CheckStatus::Warn)
        ->and($missing->fix)->toBe(__('Remove database/schema/pgsql-schema.* (§7.3)'))
        ->and($check->run($this->context)->status)->toBe(CheckStatus::Pass);
})->skip(fn (): bool => new Toolchain(storage_path('framework/testing/setup/no-home'))->which('pg_dump') !== null, 'pg_dump is on the system PATH.');

it('reports permission drift and repairs it (§5.4, DEC-26)', function (): void {
    expect(new PermissionsCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);

    $template = RoleModel::query()->findOrFail(Role::templateId());
    PermissionsTeam::run(null, fn () => $template->revokePermissionTo(Permission::UsersView->value));
    // The dry-run sync is cached until forgetSync(), as Doctor::run() does between checks.
    $this->context->forgetSync();
    $drift = new PermissionsCheck()->run($this->context);
    $fixed = new PermissionsCheck()->fix($this->context);

    expect($drift->status)->toBe(CheckStatus::Fail)
        ->and($drift->fix)->toBe('php artisan permissions:sync')
        ->and($fixed?->status)->toBe(CheckStatus::Pass)
        ->and($template->fresh()?->hasPermissionTo(Permission::UsersView->value))->toBeTrue();
});

it('needs the organisation admin template role and restores it (§5.4, DEC-21)', function (): void {
    expect(new OrganisationAdminRoleCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);

    RoleModel::query()->whereKey(Role::templateId())->delete();
    $missing = new OrganisationAdminRoleCheck()->run($this->context);
    $fixed = new OrganisationAdminRoleCheck()->fix($this->context);

    expect($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe('php artisan permissions:sync')
        ->and($fixed?->status)->toBe(CheckStatus::Pass);
});

it('needs an active super admin (§5.4)', function (): void {
    $none = new SuperAdminCheck()->run($this->context);
    User::factory()->superAdmin()->disabled()->create();
    $disabledOnly = new SuperAdminCheck()->run($this->context);
    User::factory()->superAdmin()->create();

    expect($none->status)->toBe(CheckStatus::Fail)
        ->and($none->fix)->toBe('php artisan skv:create-super-admin')
        ->and($disabledOnly->status)->toBe(CheckStatus::Fail)
        ->and(new SuperAdminCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);
});

it('reports badge drift against is_super_admin and repairs it (§5.4, DEC-28)', function (): void {
    expect(new SuperAdminBadgesCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);

    $user = User::factory()->superAdmin()->create();
    $this->context->forgetSync();
    $drift = new SuperAdminBadgesCheck()->run($this->context);
    $fixed = new SuperAdminBadgesCheck()->fix($this->context);

    expect($drift->status)->toBe(CheckStatus::Fail)
        ->and($drift->fix)->toBe('php artisan permissions:sync')
        ->and($fixed?->status)->toBe(CheckStatus::Pass)
        ->and(withPermissionsTeam(null, fn (): bool => $user->hasRole(Role::SuperAdmin->value)))->toBeTrue();
});

it('needs database sessions (§5.4)', function (): void {
    config(['session.driver' => 'database']);
    $database = new SessionDriverCheck()->run($this->context);
    config(['session.driver' => 'file']);
    $file = new SessionDriverCheck()->run($this->context);

    expect($database->status)->toBe(CheckStatus::Pass)
        ->and($file->status)->toBe(CheckStatus::Fail)
        ->and($file->fix)->toBe(__('Set SESSION_DRIVER=database in your .env'));
});

it('needs AuthenticateSession in the web group (§5.4)', function (): void {
    $registered = new AuthenticateSessionCheck()->run($this->context);
    app(HttpKernel::class)->setMiddlewareGroups(['web' => []]);
    $missing = new AuthenticateSessionCheck()->run($this->context);

    expect($registered->status)->toBe(CheckStatus::Pass)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBeNull();
});

it('fails a blank or short health-check key and writes a new one with --fix (§5.4, CD-4, F15)', function (string $key, string $message): void {
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => $key]);

    $before = new HealthCheckKeyCheck()->run($this->context);
    $result = new HealthCheckKeyCheck()->fix($this->context);
    $written = (string) new EnvFile($this->sandbox->path('.env'))->get('HEALTH_CHECK_KEY');

    expect($before->status)->toBe(CheckStatus::Fail)
        ->and($before->message)->toBe(__($message))
        ->and($before->fix)->toBe('php artisan skv:doctor --fix')
        ->and($result->status)->toBe(CheckStatus::Pass)
        ->and($result->message)->not->toContain($written)
        ->and(strlen($written))->toBe(40);
})->with([
    'blank' => ['', 'HEALTH_CHECK_KEY is empty'],
    'one short of the minimum' => [str_repeat('k', 31), 'HEALTH_CHECK_KEY is too short'],
]);

it('keeps a health-check key of the minimum length, which a monitor may use (§5.4, CD-4)', function (): void {
    $key = str_repeat('k', HealthCheckKey::MIN_LENGTH);
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => $key]);

    $result = new HealthCheckKeyCheck()->fix($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and(new EnvFile($this->sandbox->path('.env'))->get('HEALTH_CHECK_KEY'))->toBe($key);
});

it('judges the running configuration\'s health-check key in a runtime context, and .env otherwise (CD-5)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => str_repeat('k', 40)]);
    config(['skv.health_check.key' => 'short']);

    $runtime = new HealthCheckKeyCheck()->run(DoctorContext::withoutDetection($this->sandbox->basePath));

    expect(new HealthCheckKeyCheck()->run($this->context)->status)->toBe(CheckStatus::Pass)
        ->and($runtime->status)->toBe(CheckStatus::Fail)
        ->and($runtime->message)->toBe(__('HEALTH_CHECK_KEY is too short'));
});

it('needs an archive password while the backups module is on, and writes one with --fix (CD-10)', function (): void {
    useBackupsModule(false);
    new EnvFile($this->sandbox->path('.env'))->set(['BACKUP_ARCHIVE_PASSWORD' => '', 'HEALTH_CHECK_KEY' => '']);

    $off = new BackupPasswordCheck()->run($this->context);

    useBackupsModule(true);
    $missing = new BackupPasswordCheck()->run($this->context);
    $result = new BackupPasswordCheck()->fix($this->context);
    $written = (string) new EnvFile($this->sandbox->path('.env'))->get('BACKUP_ARCHIVE_PASSWORD');

    expect($off->status)->toBe(CheckStatus::Skipped)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe('php artisan skv:doctor --fix')
        ->and($result->status)->toBe(CheckStatus::Pass)
        ->and(strlen($written))->toBe(32)
        ->and($result->message)->not->toContain($written)
        ->and(new EnvFile($this->sandbox->path('.env'))->get('HEALTH_CHECK_KEY'))->toBe('');
});

it('fails while .env has an archive password that the running configuration lacks (CD-10)', function (): void {
    // A config:cache taken before the password existed: backup:run reads the configuration, not .env.
    useBackupsModule(true);
    config(['backup.backup.password' => '']);
    new EnvFile($this->sandbox->path('.env'))->set(['BACKUP_ARCHIVE_PASSWORD' => str_repeat('p', 32)]);

    $stale = new BackupPasswordCheck()->run($this->context);
    $fixed = new BackupPasswordCheck()->fix($this->context);

    expect($stale->status)->toBe(CheckStatus::Fail)
        ->and($stale->message)->toBe(__('BACKUP_ARCHIVE_PASSWORD is in .env, but the running configuration has none: a stale config cache or a blank environment variable'))
        ->and($stale->fix)->toBe('php artisan config:cache')
        ->and($fixed->status)->toBe(CheckStatus::Fail);
});

it('points the running configuration at the archive password --fix writes, as the next process boots (CD-10)', function (): void {
    useBackupsModule(true);
    config(['backup.backup.password' => null]);
    new EnvFile($this->sandbox->path('.env'))->set(['BACKUP_ARCHIVE_PASSWORD' => '']);

    $result = new BackupPasswordCheck()->fix($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and(config('backup.backup.password'))->toBe(new EnvFile($this->sandbox->path('.env'))->get('BACKUP_ARCHIVE_PASSWORD'));
});

it('never replaces an archive password that exists (CD-10)', function (): void {
    useBackupsModule(true);
    new EnvFile($this->sandbox->path('.env'))->set(['BACKUP_ARCHIVE_PASSWORD' => str_repeat('p', 32)]);

    $result = new BackupPasswordCheck()->fix($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and(new EnvFile($this->sandbox->path('.env'))->get('BACKUP_ARCHIVE_PASSWORD'))->toBe(str_repeat('p', 32));
});

it('warns about a missing or unsupported Node (§5.4)', function (string $version, CheckStatus $status) use ($stub): void {
    $stub($this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin/node');
    Process::fake(["*node' '-v'*" => Process::result($version)]);

    $result = new NodeCheck()->run($this->context);

    expect($result->status)->toBe($status)
        ->and($result->fix)->toBe($status === CheckStatus::Pass ? null : __('Install Node ^20.19 or >=22.12'));
})->with([
    'node 22' => ["v22.23.2\n", CheckStatus::Pass],
    'node 18' => ["v18.20.0\n", CheckStatus::Warn],
]);

it('needs a current Vite build and builds it with --fix (§5.4)', function () use ($stub): void {
    $missing = new AssetsCheck()->run($this->context);

    File::ensureDirectoryExists($this->sandbox->path('public/build'));
    File::put($this->sandbox->path('public/build/manifest.json'), '{}');
    $current = new AssetsCheck()->run($this->context);
    File::delete($this->sandbox->path('public/build/manifest.json'));

    $bin = $this->home.'/Library/Application Support/Herd/config/nvm/versions/node/v22.23.2/bin';
    $stub($bin.'/node');
    $stub($bin.'/npm');
    Process::fake([
        "*node' '-v'*" => Process::result("v22.23.2\n"),
        "*npm' 'ci'*" => Process::result('added 120 packages'),
        "*npm' 'run' 'build'*" => Process::result('built in 1s'),
    ]);
    $fixed = new AssetsCheck()->fix($this->context);

    expect($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe('npm ci && npm run build')
        ->and($current->status)->toBe(CheckStatus::Pass)
        // The fake build writes no manifest, so the re-run still fails; the build itself ran in the base path.
        ->and($fixed->status)->toBe(CheckStatus::Fail);
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'npm run build') && $process->path === $this->sandbox->basePath);
});

it('probes the SMTP port and accepts the log mailer (§5.4)', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    assert($server !== false);
    $port = (int) Str::afterLast((string) stream_socket_get_name($server, false), ':');
    $env = new EnvFile($this->sandbox->path('.env'));

    $env->set(['MAIL_MAILER' => 'log']);
    $log = new MailCheck()->run($this->context);
    $env->set(['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => $port]);
    $listening = new MailCheck()->run($this->context);
    fclose($server);
    $closed = new MailCheck()->run($this->context);

    expect($log)->toEqual(CheckResult::pass(__('Mail goes to the log')))
        ->and($listening->status)->toBe(CheckStatus::Pass)
        ->and($closed->status)->toBe(CheckStatus::Fail)
        ->and($closed->fix)->toBe(__("Start Herd's mail service"));
});

it('looks for pg_dump only while the backups module is on (§5.4, RL-11)', function () use ($stub): void {
    // Herd's layout: the trust rule walks up to the root two levels above the services (CD-6).
    $herdServices = $this->sandbox->path('herd/services/postgresql');
    config(['skv.modules.backups' => false]);
    $off = new PgDumpCheck($herdServices)->run($this->context);
    config(['skv.modules.backups' => true]);
    $missing = new PgDumpCheck($herdServices)->run($this->context);
    $stub($herdServices.'/18/bin/pg_dump');

    expect($off->status)->toBe(CheckStatus::Skipped)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe('PG_DUMP_BINARY_PATH=/path/to/postgresql/bin')
        ->and(new PgDumpCheck($herdServices)->run($this->context)->status)->toBe(CheckStatus::Pass);
})->skip(fn (): bool => new Toolchain(storage_path('framework/testing/setup/no-home'))->which('pg_dump') !== null, 'pg_dump is on the system PATH.');

it('honours PG_DUMP_BINARY_PATH (§5.4)', function () use ($stub): void {
    config(['skv.modules.backups' => true]);
    $stub($this->sandbox->path('pg/bin/pg_dump'));
    new EnvFile($this->sandbox->path('.env'))->set(['PG_DUMP_BINARY_PATH' => $this->sandbox->path('pg/bin')]);

    expect(new PgDumpCheck($this->sandbox->path('herd-services'))->run($this->context)->status)->toBe(CheckStatus::Pass);
});

it('never prints the Flux Pro credentials it checks (§5.4)', function (): void {
    Skv::useFluxPro(true);
    Process::fake(["*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('{"username":"me@example.com","password":"secret-key"}')]);

    $result = new FluxProCredentialsCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->message)->not->toContain('secret-key')
        ->and($result->message)->not->toContain('me@example.com');
    Process::assertRan(fn ($process): bool => $process->path === $this->sandbox->basePath);
});

it('fails without Flux Pro credentials and skips without Flux Pro (§5.4)', function (): void {
    Skv::useFluxPro(false);
    $free = new FluxProCredentialsCheck()->run($this->context);
    Skv::useFluxPro(true);
    Process::fake(["*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1)]);
    $missing = new FluxProCredentialsCheck()->run($this->context);

    expect($free->status)->toBe(CheckStatus::Skipped)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe('composer config --global http-basic.composer.fluxui.dev <email> <license-key>');
});

it('needs a working queue connection and its jobs table (§5.4)', function (): void {
    config(['queue.default' => 'database']);
    $database = new QueueCheck()->run($this->context);
    config(['queue.connections.database.table' => 'missing_jobs']);
    $noTable = new QueueCheck()->run($this->context);
    config(['queue.default' => 'nowhere']);
    $unknown = new QueueCheck()->run($this->context);

    expect($database->status)->toBe(CheckStatus::Pass)
        ->and($noTable->status)->toBe(CheckStatus::Fail)
        ->and($noTable->fix)->toBe('php artisan migrate')
        ->and($unknown->status)->toBe(CheckStatus::Fail);
});

it('warns when git does not use the kit hooks and sets them with --fix (§5.4)', function (): void {
    expect(new GitHooksCheck()->run($this->context)->status)->toBe(CheckStatus::Skipped);

    File::ensureDirectoryExists($this->sandbox->path('.git'));
    Process::fake([
        "*git' 'config' '--get' 'core.hooksPath'*" => Process::sequence()->push(Process::result(''))->push(Process::result(".githooks\n")),
        "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(''),
    ]);
    $unset = new GitHooksCheck()->run($this->context);
    $fixed = new GitHooksCheck()->fix($this->context);

    expect($unset->status)->toBe(CheckStatus::Warn)
        ->and($unset->fix)->toBe('git config core.hooksPath .githooks')
        ->and($fixed?->status)->toBe(CheckStatus::Pass);
    Process::assertRan(fn ($process): bool => str_ends_with(implode(' ', (array) $process->command), 'config core.hooksPath .githooks') && $process->path === $this->sandbox->basePath);
});

it('checks Playwright only when it was chosen (§5.4)', function () use ($withMarker): void {
    $chosen = $withMarker($this->context, ['playwright' => true]);
    $missing = new PlaywrightCheck()->run($chosen);
    File::ensureDirectoryExists($this->sandbox->path('node_modules/.bin'));
    File::put($this->sandbox->path('node_modules/.bin/playwright'), '');

    expect(new PlaywrightCheck()->run($withMarker($this->context, ['playwright' => false]))->status)->toBe(CheckStatus::Skipped)
        ->and($missing->status)->toBe(CheckStatus::Warn)
        ->and($missing->fix)->toBe('npx playwright install chromium')
        ->and(new PlaywrightCheck()->run($chosen)->status)->toBe(CheckStatus::Pass);
});

it('needs CLAUDE.md to include AGENTS.md when Claude was chosen (§5.4)', function () use ($withMarker): void {
    $chosen = $withMarker($this->context, ['claude' => true]);
    File::put($this->sandbox->path('CLAUDE.md'), "# CLAUDE.md\n");
    $missing = new ClaudeFilesCheck()->run($chosen);
    File::put($this->sandbox->path('CLAUDE.md'), "# CLAUDE.md\n\n@AGENTS.md\n");
    File::put($this->sandbox->path('AGENTS.md'), "# AGENTS\n");

    expect(new ClaudeFilesCheck()->run($this->context)->status)->toBe(CheckStatus::Skipped)
        ->and($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->fix)->toBe('php artisan boost:install')
        ->and(new ClaudeFilesCheck()->run($chosen)->status)->toBe(CheckStatus::Pass);
});

it('needs the Boost and, under Herd, the herd MCP servers (§5.4, DEC-25)', function () use ($withMarker): void {
    $write = fn (array $servers) => File::put($this->sandbox->path('.mcp.json'), json_encode(['mcpServers' => (object) $servers], JSON_THROW_ON_ERROR));
    $write(['laravel-boost' => ['command' => 'php']]);
    $withoutHerd = new McpCheck()->run($withMarker($this->context, ['claude' => true]));
    $herdMissing = new McpCheck()->run($withMarker($this->context, ['claude' => true], $this->herd));
    $write(['laravel-boost' => ['command' => 'php'], 'herd' => ['command' => 'php']]);

    expect(new McpCheck()->run($withMarker($this->context, ['claude' => false]))->status)->toBe(CheckStatus::Skipped)
        ->and($withoutHerd->status)->toBe(CheckStatus::Pass)
        ->and($herdMissing->status)->toBe(CheckStatus::Fail)
        ->and($herdMissing->fix)->toBe('php artisan skv:install --force')
        ->and(new McpCheck()->run($withMarker($this->context, ['claude' => true], $this->herd))->status)->toBe(CheckStatus::Pass);
});

it('only warns when the superpowers plugin is not installed (§5.4)', function () use ($stub, $withMarker): void {
    $chosen = $withMarker($this->context, ['claude' => true]);
    $withoutClaude = new SuperpowersCheck()->run($chosen);
    $stub($this->home.'/.local/bin/claude');
    File::ensureDirectoryExists($this->sandbox->path('.claude'));
    File::put($this->sandbox->path('.claude/settings.json'), json_encode(['enabledPlugins' => ['superpowers@superpowers-marketplace' => true]], JSON_THROW_ON_ERROR));
    Process::fake([
        "*claude' 'plugin' 'list' '--json'*" => Process::sequence()
            ->push(Process::result(json_encode([['id' => 'other@market', 'enabled' => true]], JSON_THROW_ON_ERROR)))
            ->push(Process::result(json_encode([['id' => 'superpowers@superpowers-marketplace', 'enabled' => true]], JSON_THROW_ON_ERROR))),
    ]);
    $notListed = new SuperpowersCheck()->run($chosen);

    expect($withoutClaude->status)->toBe(CheckStatus::Skipped)
        ->and($notListed->status)->toBe(CheckStatus::Warn)
        ->and($notListed->fix)->toBe('claude plugin install superpowers@superpowers-marketplace --scope project')
        ->and(new SuperpowersCheck()->run($chosen)->status)->toBe(CheckStatus::Pass);
});

it('warns about a leftover setup token and deletes it with --fix (§5.4)', function (): void {
    File::put($this->sandbox->path(SetupToken::PATH), Str::random(64));
    $before = new SetupTokenCheck()->run($this->context);

    Marker::write($this->sandbox, []);
    $leftover = new SetupTokenCheck()->run($this->context);
    $fixed = new SetupTokenCheck()->fix($this->context);

    expect($before->status)->toBe(CheckStatus::Skipped)
        ->and($leftover->status)->toBe(CheckStatus::Warn)
        ->and($leftover->fix)->toBe('php artisan skv:doctor --fix')
        ->and($fixed?->status)->toBe(CheckStatus::Pass)
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse();
});

it('checks the setup token whenever the marker file exists, also when it does not read back (L-13)', function (): void {
    File::put($this->sandbox->path(Marker::FILE), '{"answers":');
    File::put($this->sandbox->path(SetupToken::PATH), Str::random(64));

    $leftover = new SetupTokenCheck()->run($this->context);
    $fixed = new SetupTokenCheck()->fix($this->context);

    expect($leftover->status)->toBe(CheckStatus::Warn)
        ->and($fixed?->status)->toBe(CheckStatus::Pass)
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse();
});

it('fails the marker row for a marker that is a symlink or cannot be read (F3, L-09, RL-2)', function (): void {
    $file = $this->sandbox->path(Marker::FILE);
    Marker::write($this->sandbox, []);
    rename($file, $file.'.real');
    symlink($file.'.real', $file);
    $linked = new MarkerCheck()->run($this->context);

    unlink($file);
    rename($file.'.real', $file);
    chmod($file, 0000);
    $unreadable = new MarkerCheck()->run($this->context);
    chmod($file, 0600);

    $message = __('The install marker :file cannot be read', ['file' => Marker::FILE]);

    expect($linked->status)->toBe(CheckStatus::Fail)
        ->and($linked->message)->toBe($message)
        ->and($unreadable->status)->toBe(CheckStatus::Fail)
        ->and($unreadable->message)->toBe($message);
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');

it('warns without public/storage and runs storage:link with --fix (§5.4)', function (): void {
    unlink($this->sandbox->path('public/storage'));
    Process::fake(["*'artisan' 'storage:link'*" => Process::result('')]);
    $missing = new StorageLinkCheck()->run($this->context);
    new StorageLinkCheck()->fix($this->context);
    symlink($this->sandbox->path('storage/app/public'), $this->sandbox->path('public/storage'));

    expect($missing->status)->toBe(CheckStatus::Warn)
        ->and($missing->fix)->toBe('php artisan storage:link')
        ->and(new StorageLinkCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'artisan storage:link') && $process->path === $this->sandbox->basePath);
});

it('runs the doctor as setup step 8 (§5.3.3)', function (CheckStatus $status, string $expected): void {
    $check = fn (string $key): Check => new FixedStatusCheck($status, $key);
    app()->instance(Doctor::class, new Doctor([$check('first'), $check('second')]));

    $result = new DoctorStep()->run($this->sandbox);

    expect($result->status)->toBe($expected)
        ->and(new DoctorStep()->key())->toBe('doctor')
        ->and(new DoctorStep()->isComplete($this->sandbox))->toBeFalse();

    if ($expected === 'manual') {
        expect($result->command)->toBe('php artisan skv:doctor')
            ->and($result->message)->toBe(__(':count checks failed', ['count' => 2]));
    }
})->with([
    'green, with a warning' => [CheckStatus::Warn, 'done'],
    'a failing check' => [CheckStatus::Fail, 'manual'],
]);
