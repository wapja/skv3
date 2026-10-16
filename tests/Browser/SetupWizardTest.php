<?php

declare(strict_types=1);

/** covers §5.3.4, §7.2 */

use App\Setup\Detector;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('')]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    $this->previousConnection = config('database.default');
});

// afterEach, not beforeApplicationDestroyed: RefreshDatabase reads database.default again at teardown.
afterEach(function (): void {
    DB::purge('setup');
    config(['database.default' => $this->previousConnection]);
});

it('sets a demo install up on SQLite in the browser and signs in with the quick login (§7.2)', function (): void {
    useSetupWizard();

    $page = visit('/')->withHost('localhost');

    $page->assertSee(__('Start setup'))
        ->fill('appName', 'Acme')
        ->click('@setup-database-sqlite')
        ->click('@setup-start-button');

    // waitForText() takes no timeout: it retries for Playwright's 5 s. The wizard needs about ten polls, so wait in
    // steps while the in-process server answers them. The sandbox has no node: the page stops at the review.
    for ($i = 0; $i < 45 && ! str_contains($page->content(), 'data-test="setup-summary"'); $i++) {
        if (str_contains($page->content(), 'data-test="setup-complete"')) {
            $page->click('@setup-complete');
        }

        $page->wait(2);
    }

    $page->assertSee(__('Setup is done.'))
        ->assertSee('admin@demo1.localhost')
        ->click(__('Go to the login page'))
        ->assertSee(__('Quick login (local only)'))
        // GuessLocator strips every '@', so the selector holds none after the first.
        ->press('@quick-login-admin-demo1-localhost')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('sets a super-admin install up on PostgreSQL and signs the owner in once (§7.2)', function (): void {
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

    for ($i = 0; $i < 60 && ! str_contains($page->content(), 'data-test="setup-summary"'); $i++) {
        if (str_contains($page->content(), 'data-test="setup-complete"')) {
            $page->click('@setup-complete');
        }

        $page->wait(2);
    }

    // A text with an '@' counts as a CSS selector for the plugin: click the button by its data-test. The handoff lands
    // on the admin host's '/', which sends a super admin to the organisations index (TEN-1).
    $page->assertSee(__('Setup is done.'))
        ->assertSee(__('Log in as :email', ['email' => 'owner@example.com']))
        ->click('@setup-login')
        ->assertPathIs('/organisations')
        ->assertNoJavaScriptErrors();
})->skip(fn (): bool => ! new Detector(new Toolchain)->postgresReachable(), 'PostgreSQL is not reachable');
