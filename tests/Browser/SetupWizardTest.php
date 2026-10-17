<?php

declare(strict_types=1);

/** covers §5.3.4, §7.2 */

use App\Setup\Detector;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Playwright\Playwright;
use Tests\Fixtures\RequiredPostgres;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    // The commands a wizard run without Herd starts: Node answers no version, so the frontend step ends manual and the
    // page stops at the review; git init and the hooks path; composer's Flux Pro credentials where Pro is installed.
    Process::fake([
        "*node' '-v'*" => Process::result(''),
        "*git' 'init'*" => Process::result(''),
        "*git' 'config' 'core.hooksPath' '.githooks'*" => Process::result(''),
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
    ]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

/**
 * Waits up to two minutes for $selector while the in-process server answers the page's polls. The plugin retries an
 * assertion for Playwright::timeout() milliseconds (5 s by default), so the timeout is raised for this one wait and
 * restored after it. Playwright::usingTimeout() would not do: it changes only each request's timeout, not the retries.
 */
$waitFor = function (PendingAwaitablePage $page, string $selector): void {
    $previous = Playwright::timeout();
    Playwright::setTimeout(120_000);

    try {
        $page->assertPresent($selector);
    } finally {
        Playwright::setTimeout($previous);
    }
};

it('sets a demo install up on SQLite in the browser and signs in with the quick login (§7.2)', function () use ($waitFor): void {
    useSetupWizard();

    $page = visit('/')->withHost('localhost');

    $page->assertSee(__('Start setup'))
        ->fill('appName', 'Acme')
        ->click('@setup-database-sqlite')
        ->click('@setup-start-button');

    // The frontend step ends manual, so the page stops at the review. Click "Finish setup" once, after it shows: a
    // second click would reach the gate the marker closed.
    $waitFor($page, '@setup-complete');
    $page->click('@setup-complete');
    $waitFor($page, '@setup-summary');

    $page->assertSee(__('Setup is done.'))
        ->assertSee('admin@demo1.localhost')
        ->click('@setup-login')
        ->assertSee(__('Quick login (local only)'))
        // GuessLocator strips every '@', so the selector holds none after the first.
        ->press('@quick-login-admin-demo1-localhost')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('sets a super-admin install up on PostgreSQL and signs the owner in once (§7.2)', function () use ($waitFor): void {
    $context = useSetupWizard(setupSandbox(prefix: 'setup_probe_'));
    $name = DatabaseStep::databaseName($context);
    /** @var array{host: string, port: string|int, username: string, password: string} $server */
    $server = config('database.connections.pgsql');
    $admin = new PDO("pgsql:host={$server['host']};port={$server['port']};dbname=postgres", $server['username'], $server['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    // Runs after afterEach's purge of the setup connection, so no session holds the databases.
    $this->beforeApplicationDestroyed(function () use ($admin, $name): void {
        foreach ([$name, "{$name}_test"] as $database) {
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        }
    });

    $page = visit('/')->withHost('localhost');

    $page->fill('appName', 'Acme')
        ->click('@setup-database-pgsql')
        ->click('@setup-start-super_admin')
        ->fill('superAdminEmail', 'owner@example.com')
        ->fill('superAdminPassword', 'correct-horse-battery')
        ->click('@setup-start-button');

    $waitFor($page, '@setup-complete');
    $page->click('@setup-complete');
    $waitFor($page, '@setup-summary');

    // The handoff lands on the admin host's '/', which sends a super admin to the organisations index (TEN-1).
    $page->assertSee(__('Setup is done.'))
        ->assertSee(__('Log in as :email', ['email' => 'owner@example.com']))
        ->click('@setup-login')
        ->assertPathIs('/organisations')
        ->assertNoJavaScriptErrors();
})->skip(fn (): bool => RequiredPostgres::skips(fn (): bool => new Detector(new Toolchain)->postgresReachable()), 'PostgreSQL is not reachable');
