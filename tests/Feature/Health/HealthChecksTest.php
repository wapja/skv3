<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Setup\Detection;
use App\Setup\Doctor\Checks\FailedJobsCheck;
use App\Setup\Doctor\Checks\LastBackupCheck;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthReport;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    $this->context = new DoctorContext($this->sandbox->basePath, new Toolchain($this->sandbox->path('home')), Detection::none(), null);
    $this->defaultConnection = config('database.default');
});

afterEach(function (): void {
    // RefreshDatabase rolls back the default connection: give it back before the application is torn down.
    config(['database.default' => $this->defaultConnection]);
    DB::purge('health_unreachable');
});

$failedJob = fn (): bool => DB::table('failed_jobs')->insert([
    'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
    'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now(),
]);

it('passes without failed jobs and warns while some are stored (CD-3)', function () use ($failedJob): void {
    $none = new FailedJobsCheck()->run($this->context);
    $failedJob();
    $one = new FailedJobsCheck()->run($this->context);

    expect($none->status)->toBe(CheckStatus::Pass)
        ->and($one->status)->toBe(CheckStatus::Warn)
        ->and($one->message)->toBe(trans_choice(':count failed job is stored|:count failed jobs are stored', 1))
        ->and($one->fix)->toBe('php artisan queue:retry all');
});

it('fails when the failed-jobs table is missing (§4.9)', function (): void {
    config(['queue.failed.table' => 'missing_failed_jobs']);

    $result = new FailedJobsCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('missing_failed_jobs')
        ->and($result->fix)->toBe('php artisan migrate');
});

it('passes when failed jobs are not stored at all (CD-3)', function (): void {
    config(['queue.failed.driver' => 'null']);

    expect(new FailedJobsCheck()->run($this->context)->status)->toBe(CheckStatus::Pass);
});

it('skips the last backup while the backups module is off (§6.8)', function (): void {
    useBackupsModule(false);

    expect(new LastBackupCheck()->run($this->context)->status)->toBe(CheckStatus::Skipped);
});

it('fails while the backups would not be encrypted, even with a fresh backup (CD-10)', function (): void {
    useBackupsModule(true);
    config(['backup.backup.password' => null]);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->format('Y-m-d-H-i-s').'.zip', 'fresh');

    $result = new LastBackupCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('Backups are not encrypted: BACKUP_ARCHIVE_PASSWORD is empty or this PHP zip has no AES-256'))
        ->and($result->fix)->toBe('php artisan skv:doctor --fix');
});

it('fails while the encryption setting turns encryption off (CD-10)', function (): void {
    useBackupsModule(true);
    config(['backup.backup.encryption' => 'none']);
    Storage::fake('backups');
    Storage::disk('backups')->put(config('backup.backup.name').'/'.now()->format('Y-m-d-H-i-s').'.zip', 'fresh');

    expect(new LastBackupCheck()->run($this->context)->status)->toBe(CheckStatus::Fail);
});

it('fails without a backup or with one older than a day, and passes with a fresh one (CD-4)', function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    $name = (string) config('backup.backup.name');
    $this->travelTo(Carbon::parse('2026-10-15 03:00:00'));

    $none = new LastBackupCheck()->run($this->context);

    Storage::disk('backups')->put($name.'/2026-10-13-02-00-00.zip', 'old');
    $stale = new LastBackupCheck()->run($this->context);

    Storage::disk('backups')->put($name.'/2026-10-15-02-00-00.zip', 'fresh');
    $fresh = new LastBackupCheck()->run($this->context);

    expect($none->status)->toBe(CheckStatus::Fail)
        ->and($none->fix)->toBe('php artisan backup:run --only-db')
        ->and($stale->status)->toBe(CheckStatus::Fail)
        ->and($stale->message)->toBe(__('The last backup on :disk is from :time', ['disk' => 'backups', 'time' => Carbon::parse('2026-10-13 02:00:00')->diffForHumans()]))
        ->and($stale->fix)->toBe('php artisan backup:run --only-db')
        ->and($fresh->status)->toBe(CheckStatus::Pass)
        // diffForHumans() follows the app locale, so the expectation is computed the same way.
        ->and($fresh->message)->toContain(Carbon::parse('2026-10-15 02:00:00')->diffForHumans());
});

it('passes while tonight\'s backup is still running, and fails once its two-hour grace has passed (CD-4)', function (): void {
    useBackupsModule(true);
    Storage::fake('backups');
    // The package names an archive after the time its job started, so last night's is just over a day old at 02:00:30.
    Storage::disk('backups')->put(config('backup.backup.name').'/2026-10-14-02-00-01.zip', 'last night');

    $this->travelTo(Carbon::parse('2026-10-15 02:00:30'));
    $running = new LastBackupCheck()->run($this->context);
    $this->travelTo(Carbon::parse('2026-10-15 04:00:30'));
    $missed = new LastBackupCheck()->run($this->context);

    expect($running->status)->toBe(CheckStatus::Pass)
        ->and($missed->status)->toBe(CheckStatus::Fail);
});

it('skips the queue and failed-jobs checks while the default database is down, so only the database check fails (F2)', function (): void {
    useBackupsModule(false);
    config([
        'database.connections.health_unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/'.Str::uuid().'/database.sqlite', 'prefix' => ''],
        'database.default' => 'health_unreachable',
        'queue.default' => 'database',
        'queue.connections.database.connection' => null,
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'health_unreachable',
    ]);

    $report = new HealthReport(basePath: $this->sandbox->basePath)->run();
    $skipped = ['status' => CheckStatus::Skipped->value, 'message' => __('The database is not reachable')];

    expect($report['healthy'])->toBeFalse()
        ->and($report['checks']['database']['status'])->toBe(CheckStatus::Fail->value)
        ->and(Arr::only($report['checks']['queue'], ['status', 'message']))->toBe($skipped)
        ->and(Arr::only($report['checks']['failed-jobs'], ['status', 'message']))->toBe($skipped);
});

it('gives the pgsql connection a connect timeout from DB_CONNECT_TIMEOUT, 5 seconds by default (F2)', function (): void {
    $original = [
        'getenv' => getenv('DB_CONNECT_TIMEOUT'),
        'server' => $_SERVER['DB_CONNECT_TIMEOUT'] ?? null,
        'env' => $_ENV['DB_CONNECT_TIMEOUT'] ?? null,
    ];
    $timeout = function (): mixed {
        /** @var array{connections: array{pgsql: array<string, mixed>}} $config */
        $config = require config_path('database.php');
        $options = $config['connections']['pgsql']['options'] ?? [];

        return is_array($options) ? ($options[PDO::ATTR_TIMEOUT] ?? null) : null;
    };

    try {
        putenv('DB_CONNECT_TIMEOUT');
        unset($_SERVER['DB_CONNECT_TIMEOUT'], $_ENV['DB_CONNECT_TIMEOUT']);
        $default = $timeout();

        putenv('DB_CONNECT_TIMEOUT=2');
        $_SERVER['DB_CONNECT_TIMEOUT'] = $_ENV['DB_CONNECT_TIMEOUT'] = '2';
        $configured = $timeout();
    } finally {
        putenv($original['getenv'] === false ? 'DB_CONNECT_TIMEOUT' : 'DB_CONNECT_TIMEOUT='.$original['getenv']);
        unset($_SERVER['DB_CONNECT_TIMEOUT'], $_ENV['DB_CONNECT_TIMEOUT']);

        if ($original['server'] !== null) {
            $_SERVER['DB_CONNECT_TIMEOUT'] = $original['server'];
        }

        if ($original['env'] !== null) {
            $_ENV['DB_CONNECT_TIMEOUT'] = $original['env'];
        }
    }

    expect($default)->toBe(5)
        ->and($configured)->toBe(2)
        ->and((string) file_get_contents(base_path('.env.example')))->toMatch('/^DB_CONNECT_TIMEOUT=5$/m');
});

it('fails without a configured backup disk (CD-4, L-17)', function (): void {
    useBackupsModule(true);
    config(['backup.backup.destination.disks' => []]);

    $result = new LastBackupCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('No backup disk is configured'))
        ->and($result->fix)->toBe('config/backup.php');
});

it('fails while a backup disk cannot be reached (CD-4, L-17)', function (): void {
    useBackupsModule(true);
    // An undefined disk: BackupDestination::create() catches the filesystem error and the destination is unreachable.
    config(['backup.backup.destination.disks' => ['no-such-disk']]);

    $result = new LastBackupCheck()->run($this->context);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toBe(__('The backup disk :disk cannot be reached', ['disk' => 'no-such-disk']))
        ->and($result->fix)->toBe('config/filesystems.php');
});
