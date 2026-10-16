<?php

declare(strict_types=1);

/** covers §5.4 */

use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Enums\CheckStatus;
use App\Setup\Detection;
use App\Setup\Detector;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Marker;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Fixtures\FailingCheckWithThrowingFix;
use Tests\Fixtures\ThrowingCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    // The commands the checks and repairs start here: Node's version (it answers none, so the assets repair stops
    // before npm), the storage-link repair, and composer's Flux Pro credentials where Pro is installed (CI's main job).
    // The sandbox has no .git and no marker, so the git-hooks and superpowers checks start nothing.
    Process::fake([
        "*node' '-v'*" => Process::result(''),
        "*'artisan' 'storage:link'*" => Process::result(''),
        "*composer' 'config' 'http-basic.composer.fluxui.dev'*" => Process::result('', exitCode: 1),
    ]);
    $this->sandbox = setupSandbox();
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')), Detection::none(), null);
});

it('runs every check in the documented order (§5.4)', function (): void {
    expect(array_keys(app(Doctor::class)->run($this->context)))->toBe([
        'php', 'gd', 'app-key', 'marker', 'herd', 'herd-pro', 'herd-site', 'herd-secured', 'subdomains', 'apex', 'database', 'migrations',
        'test-database', 'schema-tools', 'permissions', 'organisation-admin-role', 'super-admin', 'super-admin-badges',
        'session-driver', 'authenticate-session', 'health-check-key', 'backup-password', 'node', 'assets', 'mail', 'pg-dump', 'flux-pro-credentials',
        'queue', 'failed-jobs', 'git-hooks', 'playwright', 'claude-files', 'mcp', 'superpowers', 'setup-token', 'storage-link',
    ]);
});

it('is green when nothing fails, whatever warns or skips (§5.4)', /** @param  array<string, CheckResult>  $results */ function (array $results, bool $green): void {
    expect(Doctor::isGreen($results))->toBe($green);
})->with([
    'pass, warn and skipped' => [['a' => CheckResult::pass('fine'), 'b' => CheckResult::warn('odd'), 'c' => CheckResult::skipped('later')], true],
    'one failure among passes' => [['a' => CheckResult::pass('fine'), 'b' => CheckResult::fail('broken')], false],
    'only a failure' => [['a' => CheckResult::fail('broken')], false],
    'no checks' => [[], true],
]);

it('skips the checks that do not apply without Herd or a marker (§5.4)', function (): void {
    $results = app(Doctor::class)->run($this->context);

    expect($results['herd']->status)->toBe(CheckStatus::Warn)
        ->and($results['herd-site']->status)->toBe(CheckStatus::Skipped)
        ->and($results['playwright']->status)->toBe(CheckStatus::Skipped)
        ->and($results['setup-token']->status)->toBe(CheckStatus::Skipped);
});

it('reports drift without repairing it, and repairs it with --fix (DEC-26, DEC-28, FU2-13)', function (): void {
    $badge = RoleModel::query()->whereNull('team_id')->where('name', Role::SuperAdmin->value)->firstOrFail();
    PermissionsTeam::run(null, fn () => $badge->givePermissionTo(Permission::UsersView->value));

    $before = app(Doctor::class)->run($this->context);
    $stillDrifting = $badge->fresh()?->permissions()->count();
    $after = app(Doctor::class)->run($this->context, fix: true);

    expect($before['super-admin-badges']->status)->toBe(CheckStatus::Fail)
        ->and($stillDrifting)->toBe(1)
        ->and($after['super-admin-badges']->status)->toBe(CheckStatus::Pass)
        ->and($badge->fresh()?->permissions()->count())->toBe(0);
    // The sandbox has no public/storage, so --fix runs the storage-link repair (a safe repair, §5.4).
    Process::assertRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'artisan storage:link'));
});

it('names only the exception class of a check or a fix that throws, never its message (§5.4, RL-11)', function (bool $fix): void {
    Exceptions::fake();

    $result = new Doctor([new ThrowingCheck])->run($this->context, fix: $fix)['throwing'];

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('RuntimeException')
        ->and($result->message.$result->fix)->not->toContain('secret-binding');
})->with(['without fix' => false, 'with fix' => true]);

it('reports a repair that throws, and keeps the first result with its fix command (A9, L-11)', function (): void {
    Exceptions::fake();

    $result = new Doctor([new FailingCheckWithThrowingFix])->run($this->context, fix: true)['failing'];

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe('first')
        ->and($result->fix)->toBe('first-fix');
    Exceptions::assertReported(RuntimeException::class);
});

it('treats an unreadable marker as no marker while setup builds its context (A8)', function (): void {
    File::put($this->sandbox->path(Marker::FILE), 'null');

    $context = DoctorContext::fromSetup($this->sandbox, new Toolchain($this->sandbox->path('home')));

    expect($context->marker)->toBe([
        'answers' => $this->sandbox->answers->toPublicArray(),
        'start_with' => $this->sandbox->answers->startWith,
    ]);
});

it('treats a marker it cannot read as no marker, in setup and in the running application (L-09, L-10, A8)', function (): void {
    $marker = $this->sandbox->path(Marker::FILE);
    File::put($marker, '{}');
    chmod($marker, 0000);
    $toolchain = new Toolchain($this->sandbox->path('home'));

    $setup = DoctorContext::fromSetup($this->sandbox, $toolchain);
    $application = DoctorContext::forApplication($toolchain, new Detector($toolchain, postgresProbe: fn (): bool => false), basePath: $this->sandbox->basePath);

    expect($setup->marker)->toBe([
        'answers' => $this->sandbox->answers->toPublicArray(),
        'start_with' => $this->sandbox->answers->startWith,
    ])
        ->and($application->basePath)->toBe($this->sandbox->basePath)
        ->and($application->marker)->toBeNull();
})->skip(fn (): bool => posix_geteuid() === 0, 'root reads every file');

it('reads a setting from the running configuration only in a runtime context (CD-5, F10)', function (): void {
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    new EnvFile($this->sandbox->path('.env'))->set(['MAIL_HOST' => 'from-env.test', 'MAIL_PORT' => '']);
    config(['skv.health_check.key' => true]);
    config(['mail.mailers.smtp.host' => 'from-config.test', 'mail.mailers.smtp.port' => 2525]);
    $runtime = DoctorContext::withoutDetection($this->sandbox->basePath);

    expect($this->context->runtime)->toBeFalse()
        ->and(DoctorContext::fromSetup($this->sandbox, new Toolchain($this->sandbox->path('home')))->runtime)->toBeFalse()
        ->and($runtime->runtime)->toBeTrue()
        ->and($this->context->setting('MAIL_HOST', 'mail.mailers.smtp.host'))->toBe('from-env.test')
        ->and($this->context->setting('MAIL_PORT', 'mail.mailers.smtp.port'))->toBeNull()
        ->and($runtime->setting('MAIL_HOST', 'mail.mailers.smtp.host'))->toBe('from-config.test')
        ->and($runtime->setting('MAIL_PORT', 'mail.mailers.smtp.port'))->toBe('2525')
        // env() casts HEALTH_CHECK_KEY=true to bool true: no value.
        ->and($runtime->setting('HEALTH_CHECK_KEY', 'skv.health_check.key'))->toBeNull();
});
