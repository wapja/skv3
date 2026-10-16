<?php

declare(strict_types=1);

/** covers §6.5, §7.2 */

use App\Setup\Support\Toolchain;
use App\Support\Health\HealthReport;
use App\Support\Health\SystemChecks;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

it('lets a super admin read the system status and run the checks', function (): void {
    // RL-7: the page's doctor starts no process; a check that tries gets a 'could not run' row, which fails below (F27).
    Process::preventStrayProcesses();
    Process::fake([]);
    $sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($sandbox->path('home')));
    app()->instance(HealthReport::class, new HealthReport(basePath: $sandbox->basePath));
    app()->instance(SystemChecks::class, new SystemChecks($sandbox->basePath));
    // Never the repository's storage/app/backups: the page shows "the backups module is off" instead.
    useBackupsModule(false);
    $admin = browserUser(email: 'root@example.com', superAdmin: true);

    $page = visit('/login')->withHost('admin.localhost')
        ->fill('email', $admin->email)->fill('password', 'password')->press('@login-button')
        ->assertPathIs('/dashboard')
        ->click('[data-test="nav-system.index"]')
        ->assertPathIs('/system')
        ->assertSee(__('Health'));

    $page->click('@system-run-checks')
        ->assertVisible('@system-doctor-row-database')
        ->assertNoJavaScriptErrors();

    // Doctor::run() turns a check's exception (a stray process too) into a 'could not run' row; none may appear.
    foreach (['en', 'nl'] as $locale) {
        $page->assertDontSee(Str::before(__('The check could not run (:exception).', locale: $locale), '('));
    }
});
