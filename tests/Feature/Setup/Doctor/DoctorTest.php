<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Enums\CheckStatus;
use App\Setup\Detection;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    // A catch-all is fine here: these tests assert statuses, not commands. ChecksTest must NOT copy it,
    // because the first matching handler wins and a catch-all would shadow its specific fakes.
    Process::fake(['*' => Process::result('')]);
    $this->sandbox = setupSandbox();
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')), Detection::none(), null);
});

it('runs every check in the documented order (§5.4)', function (): void {
    expect(array_keys(app(Doctor::class)->run($this->context)))->toBe([
        'php', 'gd', 'app-key', 'herd', 'herd-pro', 'herd-site', 'herd-secured', 'subdomains', 'apex', 'database', 'migrations',
        'test-database', 'schema-tools', 'permissions', 'organisation-admin-role', 'super-admin', 'super-admin-badges',
        'session-driver', 'authenticate-session', 'health-check-key', 'node', 'assets', 'mail', 'pg-dump', 'flux-pro-credentials',
        'queue', 'git-hooks', 'playwright', 'claude-files', 'mcp', 'superpowers', 'setup-token', 'storage-link',
    ]);
});

it('is green when nothing fails, whatever warns (§5.4)', function (): void {
    $results = app(Doctor::class)->run($this->context);

    expect(Doctor::isGreen($results))->toBe(collect($results)->every(fn ($result): bool => $result->status !== CheckStatus::Fail));
});

it('skips the checks that do not apply without Herd or a marker (§5.4)', function (): void {
    $results = app(Doctor::class)->run($this->context);

    expect($results['herd']->status)->toBe(CheckStatus::Warn)
        ->and($results['herd-site']->status)->toBe(CheckStatus::Skipped)
        ->and($results['playwright']->status)->toBe(CheckStatus::Skipped)
        ->and($results['setup-token']->status)->toBe(CheckStatus::Skipped);
});

it('reports drift without repairing it, and repairs it with --fix (DEC-26, DEC-28, FU2-13)', function (): void {
    $badge = Spatie\Permission\Models\Role::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->firstOrFail();
    PermissionsTeam::run(null, fn () => $badge->givePermissionTo(Permission::UsersView->value));

    $before = app(Doctor::class)->run($this->context);
    $stillDrifting = $badge->fresh()?->permissions()->count();
    $after = app(Doctor::class)->run($this->context, fix: true);

    expect($before['super-admin-badges']->status)->toBe(CheckStatus::Fail)
        ->and($stillDrifting)->toBe(1)
        ->and($after['super-admin-badges']->status)->toBe(CheckStatus::Pass)
        ->and($badge->fresh()?->permissions()->count())->toBe(0);
});
