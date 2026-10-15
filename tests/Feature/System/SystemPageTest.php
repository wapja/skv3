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
use App\Support\Health\HealthReport;
use App\Support\Health\SystemChecks;
use App\Support\Scheduler\Heartbeat;
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
    app()->bind(DoctorContext::class, function (): never {
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

it('lists the backups per destination while the module is on (§4.9)', function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->subHour()->format('Y-m-d-H-i-s').'.zip', 'archive');
    asSuperAdmin();
    onAdminHost();

    // The section's own data-test contains "backups", and the health table's last-backup row repeats the time:
    // assert on the destination row itself.
    Livewire::test('pages::system.index')
        ->assertDontSee(__('The backups module is off'))
        ->assertSeeHtml('data-test="system-backup-row-backups"')
        ->assertSeeInOrder(['system-backup-row-backups', now()->subHour()->diffForHumans(), '1']);
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

it('runs only the web-safe doctor checks on the sandbox, starting no process (RL-2, RL-7)', function (): void {
    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => '']);
    asSuperAdmin();
    onAdminHost();

    $page = Livewire::test('pages::system.index')->call('runDoctor');
    /** @var list<array{key: string, label: string, status: string, message: string}> $rows */
    $rows = $page->get('doctorRows');
    $crashed = Str::before(__('The check could not run (:exception).'), '(');

    expect(array_column($rows, 'key'))->toBe([
        'php', 'gd', 'app-key', 'marker', 'database', 'migrations', 'permissions', 'organisation-admin-role',
        'super-admin', 'super-admin-badges', 'session-driver', 'authenticate-session', 'health-check-key',
        'backup-password', 'assets', 'mail', 'pg-dump', 'queue', 'failed-jobs', 'storage-link',
    ])
        ->and(array_filter($rows, fn (array $row): bool => str_starts_with($row['message'], $crashed)))->toBe([])
        // The rows read the sandbox's .env, not the repository's.
        ->and(array_column($rows, 'status', 'key')['health-check-key'])->toBe(CheckStatus::Fail->value);
    expect(array_diff(SystemChecks::KEYS, array_map(fn (Check $check): string => $check->key(), new Doctor()->checks())))->toBe([]);

    new EnvFile($this->sandbox->path('.env'))->set(['HEALTH_CHECK_KEY' => 'a-long-random-health-check-key']);
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
