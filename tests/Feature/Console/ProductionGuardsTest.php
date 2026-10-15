<?php

declare(strict_types=1);

/** covers §4.12, §5.2, §5.3.3 */

use App\Models\Organisation;
use App\Setup\Detector;
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
    app()->instance(Detector::class, new Detector(new Toolchain($this->sandbox->path('home'))));
    $this->previousConnection = config('database.default');
    $this->environment = app()->environment();
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    app()['env'] = $this->environment;
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('refuses skv:install in production before anything is written (CD-9, A57)', function (): void {
    app()['env'] = 'production';
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    $exit = Artisan::call('skv:install', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('skv:install sets up a development install and does not run in production. Follow the production checklist in the README.'))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
});

it('refuses the demo data outside local development before anything is written (CD-9)', function (): void {
    // The detector probes Herd and PostgreSQL; the answers default to start_with=demo.
    Process::fake(['*' => Process::result('')]);
    app()['env'] = 'staging';
    $before = (string) file_get_contents($this->sandbox->path('.env'));

    $exit = Artisan::call('skv:install', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('Demo data runs only in local development. Pass "start_with": "super_admin" with your own login.'))
        ->and(file_get_contents($this->sandbox->path('.env')))->toBe($before);
});

it('only prepares .env, the key and the generated secrets when the target is a production install (CD-9, CD-10, A58)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['APP_ENV' => 'production', 'APP_KEY' => '', 'HEALTH_CHECK_KEY' => '', 'BACKUP_ARCHIVE_PASSWORD' => '']);
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
});

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
