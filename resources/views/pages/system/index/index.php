<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Enums\CheckStatus;
use App\Support\Backups\BackupDestinations;
use App\Support\ByteSize;
use App\Support\Health\HealthReport;
use App\Support\Health\OpsCache;
use App\Support\Health\SystemChecks;
use App\Support\Scheduler\Heartbeat;
use App\Support\Scheduler\ScheduledTasks;
use App\Support\Skv;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('System status')] class extends Component
{
    /** CD-16: the calls each user may make per action and minute. */
    private const int ATTEMPTS_PER_MINUTE = 6;

    /** @var list<array{key: string, label: string, status: string, message: string}>|null the last on-demand doctor run (RL-6) */
    #[Locked]
    public ?array $doctorRows = null;

    /** CD-16: the last refresh or doctor run was refused for too many attempts. */
    #[Locked]
    public bool $rateLimited = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        Gate::authorize(Permission::SystemHealth);
    }

    /** Runs the health checks again and caches the new report (§4.9), at most six times a minute (CD-16). */
    public function refreshHealth(): void
    {
        Gate::authorize(Permission::SystemHealth);

        $this->withinRateLimit('refreshHealth', function (): void {
            try {
                app(HealthReport::class)->refresh();
            } catch (Throwable $exception) {
                // F4: a broken ops store shows the report as unavailable, never a 500.
                report($exception);
            }

            unset($this->health);
        });
    }

    /** Runs the web-safe doctor checks (RL-7), at most six times a minute (CD-16). */
    public function runDoctor(): void
    {
        Gate::authorize(Permission::SystemHealth);

        $this->withinRateLimit('runDoctor', function (): void {
            $this->doctorRows = app(SystemChecks::class)->rows();
        });
    }

    /**
     * The cached health report as display rows; PHP code calls this method, never the magic computed property.
     *
     * @return array{available: bool, healthy: bool, checked: string, rows: list<array{key: string, label: string, status: string, color: string, message: string}>}
     */
    #[Computed]
    public function health(): array
    {
        try {
            $report = app(HealthReport::class)->cached();
        } catch (Throwable $exception) {
            // F4: the ops store cannot be read; the rest of the page still renders.
            report($exception);

            return ['available' => false, 'healthy' => false, 'checked' => '', 'rows' => []];
        }

        $rows = [];

        foreach ($report['checks'] as $key => $check) {
            $status = CheckStatus::from($check['status']);
            $rows[] = ['key' => $key, 'label' => $check['label'], 'status' => $status->label(), 'color' => $status->color(), 'message' => $check['message']];
        }

        return ['available' => true, 'healthy' => $report['healthy'], 'checked' => Carbon::parse($report['checked_at'])->diffForHumans(), 'rows' => $rows];
    }

    /**
     * @return array{enabled: bool, destinations: list<array{disk: string, reachable: bool, count: ?int, newest: ?string, size: ?string}>}
     */
    #[Computed]
    public function backups(): array
    {
        if (! Skv::moduleEnabled('backups')) {
            return ['enabled' => false, 'destinations' => []];
        }

        $destinations = [];

        foreach (BackupDestinations::all() as $destination) {
            try {
                $reachable = $destination->isReachable();
                $newest = $reachable ? $destination->newestBackup() : null;
                $destinations[] = [
                    'disk' => $destination->diskName(),
                    'reachable' => $reachable,
                    'count' => $reachable ? $destination->backups()->count() : null,
                    'newest' => $newest?->date()->diffForHumans(),
                    'size' => $reachable ? ByteSize::format($destination->usedStorage()) : null,
                ];
            } catch (Throwable $exception) {
                // F4: a remote disk that fails after answering isReachable() reads as unreachable, never a 500.
                report($exception);
                $destinations[] = ['disk' => $destination->diskName(), 'reachable' => false, 'count' => null, 'newest' => null, 'size' => null];
            }
        }

        return ['enabled' => true, 'destinations' => $destinations];
    }

    /**
     * @return array{heartbeat: ?string, heartbeatAvailable: bool, tasks: list<array{key: string, task: string, expression: string, next: string, active: bool}>}
     */
    #[Computed]
    public function schedule(): array
    {
        $tasks = array_map(fn (array $task): array => [
            ...$task,
            'key' => Str::slug(str_replace(':', ' ', $task['task'])),
            'next' => Carbon::parse($task['next'])->diffForHumans(),
        ], ScheduledTasks::all());

        try {
            $heartbeat = app(Heartbeat::class)->last()?->diffForHumans();
        } catch (Throwable $exception) {
            // F4: an unreadable ops store is not "the scheduler has not run yet".
            report($exception);

            return ['heartbeat' => null, 'heartbeatAvailable' => false, 'tasks' => $tasks];
        }

        return ['heartbeat' => $heartbeat, 'heartbeatAvailable' => true, 'tasks' => $tasks];
    }

    /**
     * The doctor rows with their translated status.
     *
     * @return list<array{key: string, label: string, status: string, color: string, message: string}>
     */
    #[Computed]
    public function doctor(): array
    {
        return array_map(function (array $row): array {
            $status = CheckStatus::from($row['status']);

            return [...$row, 'status' => $status->label(), 'color' => $status->color()];
        }, $this->doctorRows ?? []);
    }

    /**
     * CD-16: runs $callback at most ATTEMPTS_PER_MINUTE times a minute per user and action, counted on the ops store
     * (RL-11), as RateLimiter::attempt() does. Above that the page keeps its rows and says so. A broken ops store lets
     * the call through (reported), as /health-check's throttle fails open (F4).
     */
    private function withinRateLimit(string $action, Closure $callback): void
    {
        $key = 'system:'.$action.':'.Auth::id();

        try {
            $limiter = new RateLimiter(OpsCache::store());

            if ($limiter->tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
                $this->rateLimited = true;

                return;
            }

            $limiter->hit($key, 60);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->rateLimited = false;
        $callback();
    }
};
