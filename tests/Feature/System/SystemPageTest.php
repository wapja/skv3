<?php

declare(strict_types=1);

/** covers §6.5, §4.9 */

use App\Enums\CheckStatus;
use App\Models\Organisation;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Support\ByteSize;
use App\Support\Health\HealthReport;
use App\Support\Health\SystemChecks;
use App\Support\Scheduler\Heartbeat;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fixtures\FixedStatusCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    // RL-2: forApplication() starts herd processes and reads this repository's .env; web code never resolves it.
    app()->bind(function (): DoctorContext {
        throw new LogicException('Web code builds its doctor context with DoctorContext::withoutDetection().');
    });
    app()->instance(HealthReport::class, new HealthReport(basePath: $this->sandbox->basePath));
    app()->instance(SystemChecks::class, new SystemChecks($this->sandbox->basePath));
    // On PostgreSQL the module is on by default: never list or create the repository's storage/app/backups.
    Storage::fake('backups');
});

it('shows the page to super admins on the admin host only (§6.5)', function (): void {
    asSuperAdmin();
    onAdminHost();

    $this->get(route('system.index'))->assertOk()->assertSee(__('System status'))->assertSee(__('Health'));
});

it('answers 404 on a tenant host, also for a super admin (§6.2)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    asSuperAdmin();
    onTenant($organisation);

    $this->get(route('system.index'))->assertNotFound();
});

it('refuses an organisation admin at mount (§6.5)', function (): void {
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::system.index')->assertForbidden();
});

it('shows the cached health report and refreshes it on request (§4.9)', function (): void {
    useBackupsModule(false);
    asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::system.index')
        ->assertSee(__('Failed jobs'))
        ->assertSee(__('The backups module is off'));

    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'probe')], $this->sandbox->basePath));

    $page->call('refreshHealth')->assertSee('broken')->assertSee(__('Unhealthy'));
});

it('lists the backups per destination while the module is on (§4.9, L-40)', function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->subHour()->format('Y-m-d-H-i-s').'.zip', 'archive');
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::system.index')
        ->assertDontSee(__('The backups module is off'))
        ->assertSeeHtml('data-test="system-backup-row-backups"')
        ->assertSeeHtml('data-test="system-backup-newest-backups">'.now()->subHour()->diffForHumans().'</span>')
        ->assertSeeHtml('data-test="system-backup-count-backups">1</span>')
        ->assertSeeHtml('data-test="system-backup-size-backups">'.ByteSize::format(7).'</span>');
});

it('lists the schedule and the scheduler heartbeat (RL-8)', function (): void {
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::system.index')
        ->assertSee('backup:run --only-db')
        ->assertSee(__('The scheduler has not run yet'));

    app(Heartbeat::class)->beat();

    Livewire::test('pages::system.index')->assertDontSee(__('The scheduler has not run yet'));
});

it('runs only the web-safe doctor checks on the sandbox, starting no process (RL-2, RL-7, F12)', function (): void {
    // CD-5: the page's health-check-key row judges the running configuration, whatever the sandbox's .env holds.
    config(['skv.health_check.key' => '']);
    new EnvFile($this->sandbox->path('.env'))->set([
        'HEALTH_CHECK_KEY' => str_repeat('k', 40),
        'APP_URL' => 'http://my-app.test',
        'TENANCY_APEX_DOMAIN' => 'other.test',
    ]);
    asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::system.index')->call('runDoctor');
    /** @var list<array{key: string, label: string, status: string, message: string}> $rows */
    $rows = $page->get('doctorRows');
    $crashed = Str::before(__('The check could not run (:exception).'), '(');

    expect(array_column($rows, 'key'))->toBe([
        'php', 'gd', 'app-key', 'marker', 'apex', 'database', 'migrations', 'permissions', 'organisation-admin-role',
        'super-admin', 'super-admin-badges', 'session-driver', 'authenticate-session', 'health-check-key',
        'backup-password', 'assets', 'mail', 'pg-dump', 'queue', 'failed-jobs', 'storage-link',
    ])
        ->and(array_filter($rows, fn (array $row): bool => str_starts_with($row['message'], $crashed)))->toBe([])
        ->and(array_column($rows, 'status', 'key')['health-check-key'])->toBe(CheckStatus::Fail->value)
        // F12: the apex row reads the sandbox's .env, not the repository's.
        ->and(array_column($rows, 'status', 'key')['apex'])->toBe(CheckStatus::Fail->value);
    expect(array_diff(SystemChecks::KEYS, array_map(fn (Check $check): string => $check->key(), new Doctor()->checks())))->toBe([]);

    config(['skv.health_check.key' => str_repeat('k', 40)]);
    /** @var list<array{key: string, label: string, status: string, message: string}> $again */
    $again = $page->call('runDoctor')->get('doctorRows');

    expect(array_column($again, 'status', 'key')['health-check-key'])->toBe(CheckStatus::Pass->value);
    Process::assertNothingRan();
});

it('re-authorises the actions (§6.5)', function (string $action): void {
    asSuperAdmin();
    onAdminHost();
    $page = Livewire::test('pages::system.index');
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));

    $page->call($action)->assertForbidden();
})->with(['refreshHealth', 'runDoctor']);

it('renders the schedule without booting the console kernel (CD-8, F8)', function (): void {
    asSuperAdmin();
    onAdminHost();
    $kernel = $this->spy(Kernel::class);

    $this->get(route('system.index'))->assertOk()
        ->assertSeeHtml('data-test="system-task-backup-run-only-db"')
        ->assertSeeHtml('data-test="system-task-scheduler-heartbeat"');

    $kernel->shouldNotHaveReceived('bootstrap');
    $kernel->shouldNotHaveReceived('all');
});

it('shows the health report and the heartbeat as unavailable while the ops store is broken, never a 500 (F4)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('system.index'))->assertOk()
        ->assertSee(__('The health report is not available right now'))
        ->assertSee(__('The scheduler heartbeat is not available right now'))
        ->assertDontSee(__('The scheduler has not run yet'))
        ->assertSeeHtml('data-test="system-task-backup-run-only-db"');

    Livewire::test('pages::system.index')
        ->call('refreshHealth')
        ->assertSee(__('The health report is not available right now'));

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('shows a backup disk that fails after answering as unreachable, never a 500 (F4)', function (): void {
    Exceptions::fake();
    useBackupsModule(true);
    // The health section keeps off this disk: LastBackupCheck reads the backups disk too.
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'probe')], $this->sandbox->basePath));
    $archive = config('backup.backup.name').'/'.now()->subHour()->format('Y-m-d-H-i-s').'.zip';
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('files')->andReturn([]);
    $disk->shouldReceive('allFiles')->andReturn([$archive]);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('size')->andThrow(new RuntimeException('The remote disk timed out.'));
    Storage::set('backups', $disk);
    asSuperAdmin();
    onAdminHost();

    $this->get(route('system.index'))->assertOk()
        ->assertSeeHtml('data-test="system-backup-row-backups"')
        ->assertSee(__('Not reachable'))
        ->assertSeeHtml('data-test="system-backup-count-backups">—</span>')
        ->assertSeeHtml('data-test="system-backup-size-backups">—</span>');

    Exceptions::assertReported(RuntimeException::class);
});

it('shows each task\'s cron, next run and whether it is off (L-42)', function (bool $enabled): void {
    $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
    useBackupsModule($enabled);
    asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::system.index')
        ->assertSeeHtml('data-test="system-task-expression-backup-run-only-db">0 2 * * *</code>')
        ->assertSeeHtml('data-test="system-task-next-backup-run-only-db">'.Carbon::parse('2026-10-17 02:00:00')->diffForHumans().'</span>')
        ->assertDontSeeHtml('data-test="system-task-off-activitylog-clean-force"');

    if ($enabled) {
        $page->assertDontSeeHtml('data-test="system-task-off-backup-run-only-db"');
    } else {
        $page->assertSeeHtml('data-test="system-task-off-backup-run-only-db"');
    }
})->with(['module on' => true, 'module off' => false]);

it('allows six doctor runs a minute per user, counted on the ops store (CD-16, L-45)', function (): void {
    config(['skv.ops_cache_store' => 'ops-test']);
    config(['cache.stores.ops-test' => ['driver' => 'array']]);
    // CD-5: the page's health-check-key row judges the running configuration, never the sandbox .env, so the
    // developer's own HEALTH_CHECK_KEY never decides this test.
    config(['skv.health_check.key' => '']);
    $admin = asSuperAdmin();
    onAdminHost();
    $page = Livewire::test('pages::system.index');

    for ($run = 0; $run < 6; $run++) {
        $page->call('runDoctor')->assertDontSee(__('Too many attempts. Try again in a minute.'));
    }

    // The seventh run would see a usable key; a refused run keeps the old rows.
    config(['skv.health_check.key' => str_repeat('k', 40)]);
    $page->call('runDoctor')->assertSee(__('Too many attempts. Try again in a minute.'));
    /** @var list<array{key: string, label: string, status: string, message: string}> $rows */
    $rows = $page->get('doctorRows');

    expect(array_column($rows, 'status', 'key')['health-check-key'])->toBe(CheckStatus::Fail->value)
        ->and(new RateLimiter(Cache::store('ops-test'))->attempts('system:runDoctor:'.$admin->id))->toBe(6)
        ->and(new RateLimiter(Cache::store())->attempts('system:runDoctor:'.$admin->id))->toBe(0);

    // The refresh has its own budget.
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'probe')], $this->sandbox->basePath));
    $page->call('refreshHealth')->assertSee('broken')->assertDontSee(__('Too many attempts. Try again in a minute.'));
});

it('keeps the health rows when the seventh refresh in a minute is refused (CD-16, L-45)', function (): void {
    asSuperAdmin();
    onAdminHost();
    $page = Livewire::test('pages::system.index');

    for ($run = 0; $run < 6; $run++) {
        $page->call('refreshHealth');
    }

    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'probe')], $this->sandbox->basePath));

    $page->call('refreshHealth')
        ->assertSee(__('Too many attempts. Try again in a minute.'))
        ->assertDontSee('broken');
});

it('names the doctor section apart from the check column in Dutch (CD-12, L-44)', function (): void {
    app()->setLocale('nl');

    expect(__('Doctor'))->toBe('Diagnose')
        ->and(__('Check'))->toBe('Controle');
});
