<?php

declare(strict_types=1);

/** covers §6.5, §7.2 */

use App\Setup\Support\Toolchain;
use App\Support\Health\HealthReport;
use App\Support\Health\SystemChecks;

it('lets a super admin read the system status and run the checks', function (): void {
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
});
