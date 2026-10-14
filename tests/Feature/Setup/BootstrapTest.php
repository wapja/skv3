<?php

declare(strict_types=1);

/** covers §5.2 */

use App\Setup\SetupToken;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->context = setupSandbox();
    File::delete($this->context->path('.env'));
    File::copy(base_path('.env.example'), $this->context->path('.env.example'));
    // No Herd in this toolchain: a home without Herd's bin directory.
    $this->toolchain = new Toolchain($this->context->path('home'));
    app()->instance(Toolchain::class, $this->toolchain);
});

it('creates .env, an APP_KEY and the SQLite file, then migrates (§5.2 points 1, 2, 4)', function (): void {
    Process::fake(["*'artisan' 'migrate' '--graceful' '--force'*" => Process::result('Migrated')]);

    $actions = new Bootstrap($this->context->basePath, $this->toolchain)->run();
    $env = new EnvFile($this->context->path('.env'));

    expect($env->get('APP_KEY'))->toStartWith('base64:')
        ->and(is_file($this->context->path('database/database.sqlite')))->toBeTrue()
        ->and($actions)->not->toBeEmpty();

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'migrate --graceful --force')
        && $process->path === $this->context->basePath);
});

it('never rotates an existing APP_KEY and is idempotent (§5.2)', function (): void {
    Process::fake(["*'artisan' 'migrate'*" => Process::result('Nothing to migrate')]);
    $bootstrap = new Bootstrap($this->context->basePath, $this->toolchain);

    $bootstrap->run();
    $key = new EnvFile($this->context->path('.env'))->get('APP_KEY');
    $bootstrap->run();

    expect(new EnvFile($this->context->path('.env'))->get('APP_KEY'))->toBe($key);
});

it('rewrites a localhost APP_URL to the Herd address when Herd is present (§5.2 point 3)', function (): void {
    $herd = $this->context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    Process::fake([
        "*herd' 'tld'*" => Process::result("test\n"),
        "*'artisan' 'migrate'*" => Process::result('Migrated'),
    ]);

    new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect(new EnvFile($this->context->path('.env'))->get('APP_URL'))->toBe('http://'.$this->context->folderName().'.test');
});

it('still reads the Herd tld when the installer appends --quiet (RL-13)', function (): void {
    $herd = $this->context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Bootstrap::class, new Bootstrap($this->context->basePath, $this->toolchain));

    // herd is a Symfony Console app: it prints nothing when it inherits the SHELL_VERBOSITY=-1 that --quiet exports.
    Process::fake([
        "*herd' 'tld'*" => fn (PendingProcess $process): ProcessResult => Process::result(
            ($process->environment['SHELL_VERBOSITY'] ?? getenv('SHELL_VERBOSITY')) === '-1' ? '' : "test\n",
        ),
        "*'artisan' 'migrate'*" => Process::result('Migrated'),
    ]);

    expect(Artisan::call('skv:bootstrap', ['--no-interaction' => true, '--quiet' => true]))->toBe(0)
        ->and(new EnvFile($this->context->path('.env'))->get('APP_URL'))->toBe('http://'.$this->context->folderName().'.test');
});

it('leaves a non-default APP_URL and a non-sqlite database alone (§5.2)', function (): void {
    File::copy(base_path('.env.example'), $this->context->path('.env'));
    $env = new EnvFile($this->context->path('.env'));
    $env->set(['APP_URL' => 'https://my-app.example', 'DB_CONNECTION' => 'pgsql']);

    Process::fake();

    new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect($env->get('APP_URL'))->toBe('https://my-app.example')
        ->and(is_file($this->context->path('database/database.sqlite')))->toBeFalse();
    Process::assertNothingRan();
});

it('never rewrites a custom or https APP_URL, even when Herd is present (§5.2 point 3)', function (string $appUrl): void {
    $herd = $this->context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    File::copy(base_path('.env.example'), $this->context->path('.env'));
    $env = new EnvFile($this->context->path('.env'));
    $env->set(['APP_URL' => $appUrl, 'DB_CONNECTION' => 'pgsql']);

    Process::fake(["*herd' 'tld'*" => Process::result("test\n")]);

    new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect($this->toolchain->herd())->not->toBeNull()
        ->and($env->get('APP_URL'))->toBe($appUrl);
    Process::assertNotRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), ' tld'));
})->with([
    'a Herd-secured address' => 'https://my-app.test',
    'a custom http address' => 'http://my-app.example',
    'localhost on another port' => 'http://localhost:3000',
]);

it('returns early while the installer defers its hooks (§5.2)', function (): void {
    app()->instance(Bootstrap::class, new Bootstrap($this->context->basePath, $this->toolchain));
    putenv('LARAVEL_INSTALLER_DEFER_HOOKS=1');

    try {
        expect(Artisan::call('skv:bootstrap', ['--no-interaction' => true]))->toBe(0);
    } finally {
        putenv('LARAVEL_INSTALLER_DEFER_HOOKS');
    }

    expect(is_file($this->context->path('.env')))->toBeFalse();
});

it('tells the user to finish with skv:install (RL-1)', function (): void {
    Process::fake(["*'artisan' 'migrate'*" => Process::result('Migrated')]);
    app()->instance(Bootstrap::class, new Bootstrap($this->context->basePath, $this->toolchain));

    expect(Artisan::call('skv:bootstrap', ['--no-interaction' => true, '--no-ansi' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('php artisan skv:install');
});

it('survives a herd tld that times out (A5)', function (): void {
    $herd = $this->context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    Process::fake(["*herd' 'tld'*" => fn () => throw new RuntimeException('timed out'), '*' => Process::result('')]);

    $actions = new Bootstrap($this->context->basePath, $this->toolchain)->run();

    expect($actions)->not->toContain(__('Set APP_URL to the Herd address'))
        ->and(new EnvFile($this->context->path('.env'))->get('APP_URL'))->toBe('http://localhost');
});

it('creates the one-time setup token (§5.2 point 5, RL-10)', function (): void {
    Process::fake(["*'artisan' 'migrate' '--graceful' '--force'*" => Process::result('Migrated')]);
    $context = $this->context;
    $bootstrap = new Bootstrap($context->basePath, $this->toolchain);

    $actions = $bootstrap->run();

    expect($actions)->toContain(__('Created the one-time setup token'))
        ->and(new SetupToken($context->basePath)->value())->toMatch('/^[0-9a-f]{64}$/');
});
