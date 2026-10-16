<?php

declare(strict_types=1);

/** covers §4.12, §5.2, §5.3.3 */

use App\Models\Organisation;
use App\Setup\SetupContext;
use App\Setup\SetupToken;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\BootstrapRunner;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Tenancy\Facades\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\FailingBootstrap;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    app()->instance(SetupContext::class, $this->sandbox);
    // Sandboxed like InstallCommandTest: a skv:install that gets past a guard (or a regressed one) must never
    // write the repository's .env, database/database.sqlite or setup token.
    app()->instance(Bootstrap::class, new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home'))));
    app()->instance(BootstrapRunner::class, app(Bootstrap::class));
    withoutPostgresProbe();
    $this->previousConnection = config('database.default');
    $this->environment = app()->environment();
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    app()['env'] = $this->environment;
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('refuses skv:install outside local, testing and staging before anything is written (CD-3, L-61)', function (string $environment): void {
    app()['env'] = $environment;
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    $exit = Artisan::call('skv:install', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('skv:install runs only in the local, testing and staging environments (APP_ENV=:env).', ['env' => $environment]))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
})->with(['production', 'prod', 'live']);

it('refuses the demo data outside local development before anything is written (CD-9)', function (): void {
    // The beforeEach fakes no command and the detector starts none: a regressed guard trips the stray-process
    // check in the bootstrap's migrate, after it has written APP_KEY (L-57).
    app()['env'] = 'staging';
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    $exit = Artisan::call('skv:install', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('Demo data runs only in local development. Pass "start_with": "super_admin" with your own login.'))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
});

it('lets a staging install with its own super admin past the guards (CD-3, CD-9, L-58)', function (): void {
    app()->instance(BootstrapRunner::class, new FailingBootstrap);
    app()['env'] = 'staging';
    $answers = $this->sandbox->path('answers.json');
    file_put_contents($answers, json_encode([
        'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery', 'modules' => [], 'https' => false, 'claude' => false,
    ], JSON_THROW_ON_ERROR));

    $exit = Artisan::call('skv:install', ['--answers' => '@'.$answers, '--no-interaction' => true]);
    $output = Artisan::output();

    // Past both refusals, the run reaches the bootstrap, which the fixture fails before anything is written.
    expect($exit)->toBe(1)
        ->and($output)->toContain(__('Setup stopped at :step: :message', ['step' => __('Bootstrap'), 'message' => 'The command "php artisan migrate" failed.']))
        ->not->toContain(__('Demo data runs only in local development. Pass "start_with": "super_admin" with your own login.'))
        ->not->toContain(__('skv:install runs only in the local, testing and staging environments (APP_ENV=:env).', ['env' => 'staging']));
});

it('only prepares .env, the key and the generated secrets when the target is outside local, testing and staging (CD-3, CD-9, CD-10, A58)', function (string $environment): void {
    new EnvFile($this->sandbox->path('.env'))->set(['APP_ENV' => $environment, 'APP_KEY' => '', 'HEALTH_CHECK_KEY' => '', 'BACKUP_ARCHIVE_PASSWORD' => '']);
    $bootstrap = new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')));

    $actions = $bootstrap->run();
    $env = new EnvFile($this->sandbox->path('.env'));
    $healthKey = (string) $env->get('HEALTH_CHECK_KEY');
    $archivePassword = (string) $env->get('BACKUP_ARCHIVE_PASSWORD');

    expect($bootstrap->isProduction())->toBeTrue()
        ->and($actions)->toBe([
            __('Generated an application key'),
            __('Generated :key', ['key' => 'HEALTH_CHECK_KEY']),
            __('Generated :key', ['key' => 'BACKUP_ARCHIVE_PASSWORD']),
        ])
        ->and((string) $env->get('APP_KEY'))->toStartWith('base64:')
        ->and(strlen($healthKey))->toBe(40)
        ->and(strlen($archivePassword))->toBe(32)
        ->and(implode("\n", $actions))->not->toContain($healthKey)->not->toContain($archivePassword)
        ->and($env->get('APP_URL'))->toBe('http://localhost')
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse()
        ->and(is_file($this->sandbox->path('database/database.sqlite')))->toBeFalse();
    Process::assertNothingRan();
})->with(['production', 'prod', 'live']);

it('takes the production half when the process environment says production, whatever .env says (CD-3, L-60)', function (string $source): void {
    $previousEnv = getenv('APP_ENV');
    $previousServer = $_SERVER['APP_ENV'] ?? null;
    $this->beforeApplicationDestroyed(function () use ($previousEnv, $previousServer): void {
        putenv($previousEnv === false ? 'APP_ENV' : 'APP_ENV='.$previousEnv);

        if ($previousServer === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $previousServer;
        }
    });
    new EnvFile($this->sandbox->path('.env'))->set(['APP_ENV' => 'local', 'APP_KEY' => '', 'HEALTH_CHECK_KEY' => '', 'BACKUP_ARCHIVE_PASSWORD' => '']);

    if ($source === 'getenv') {
        putenv('APP_ENV=production');
    } else {
        $_SERVER['APP_ENV'] = 'production';
    }

    $bootstrap = new Bootstrap($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')));
    $actions = $bootstrap->run();

    expect($bootstrap->isProduction())->toBeTrue()
        ->and($actions)->toBe([
            __('Generated an application key'),
            __('Generated :key', ['key' => 'HEALTH_CHECK_KEY']),
            __('Generated :key', ['key' => 'BACKUP_ARCHIVE_PASSWORD']),
        ])
        ->and(is_file($this->sandbox->path(SetupToken::PATH)))->toBeFalse()
        ->and(is_file($this->sandbox->path('database/database.sqlite')))->toBeFalse();
    Process::assertNothingRan();
})->with(['the process environment' => 'getenv', 'the server variables' => 'server']);

it('tells a production bootstrap to follow the checklist instead of the wizard, and never prints a secret (CD-9, CD-10)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['APP_ENV' => 'production']);

    Artisan::call('skv:bootstrap');
    $output = Artisan::output();
    $env = new EnvFile($this->sandbox->path('.env'));

    expect($output)->toContain(__('This is a production install: follow the production checklist in the README.'))
        ->not->toContain('skv:install')
        ->not->toContain((string) $env->get('HEALTH_CHECK_KEY'))
        ->not->toContain((string) $env->get('BACKUP_ARCHIVE_PASSWORD'));
});

it('seeds the demo data only in local and testing (CD-9, A60)', function (string $environment, bool $demo): void {
    app()['env'] = $environment;

    // --force: without it db:seed asks to confirm in production and cancels before the seeder runs.
    $exit = Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect($exit)->toBe(0)
        ->and(Tenant::bypass(fn (): bool => Organisation::query()->where('slug', 'demo1')->exists()))->toBe($demo);
})->with([
    'local' => ['local', true],
    'testing' => ['testing', true],
    'staging' => ['staging', false],
    'production' => ['production', false],
]);
