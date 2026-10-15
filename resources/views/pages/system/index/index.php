<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Enums\CheckStatus;
use App\Support\Backups\BackupDestinations;
use App\Support\Health\HealthReport;
use App\Support\Health\SystemChecks;
use App\Support\Scheduler\Heartbeat;
use App\Support\Scheduler\ScheduledTasks;
use App\Support\Skv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('System status')] class extends Component
{
    /** @var list<array{key: string, label: string, status: string, message: string}>|null the last on-demand doctor run (RL-6) */
    #[Locked]
    public ?array $doctorRows = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        Gate::authorize(Permission::SystemHealth);
    }

    /** Runs the health checks again and caches the new report (§4.9). */
    public function refreshHealth(): void
    {
        Gate::authorize(Permission::SystemHealth);

        app(HealthReport::class)->refresh();
        unset($this->health);
    }

    /** Runs the web-safe doctor checks (RL-7). */
    public function runDoctor(): void
    {
        Gate::authorize(Permission::SystemHealth);

        $this->doctorRows = app(SystemChecks::class)->rows();
    }

    /**
     * The cached health report as display rows; PHP code calls this method, never the magic computed property.
     *
     * @return array{healthy: bool, checked: string, rows: list<array{key: string, label: string, status: string, color: string, message: string}>}
     */
    #[Computed]
    public function health(): array
    {
        $report = app(HealthReport::class)->cached();
        $rows = [];

        foreach ($report['checks'] as $key => $check) {
            $status = CheckStatus::from($check['status']);
            $rows[] = ['key' => $key, 'label' => $check['label'], 'status' => $status->label(), 'color' => $status->color(), 'message' => $check['message']];
        }

        return ['healthy' => $report['healthy'], 'checked' => Carbon::parse($report['checked_at'])->diffForHumans(), 'rows' => $rows];
    }

    /**
     * @return array{enabled: bool, destinations: list<array{disk: string, reachable: bool, count: int, newest: ?string, size: ?string}>}
     */
    #[Computed]
    public function backups(): array
    {
        if (! Skv::moduleEnabled('backups')) {
            return ['enabled' => false, 'destinations' => []];
        }

        $destinations = [];

        foreach (BackupDestinations::all() as $destination) {
            $reachable = $destination->isReachable();
            $newest = $reachable ? $destination->newestBackup() : null;
            $destinations[] = [
                'disk' => $destination->diskName(),
                'reachable' => $reachable,
                'count' => $reachable ? $destination->backups()->count() : 0,
                'newest' => $newest?->date()->diffForHumans(),
                'size' => $reachable ? Number::fileSize($destination->usedStorage()) : null,
            ];
        }

        return ['enabled' => true, 'destinations' => $destinations];
    }

    /**
     * @return array{heartbeat: ?string, tasks: list<array{task: string, expression: string, next: string, active: bool}>}
     */
    #[Computed]
    public function schedule(): array
    {
        $tasks = array_map(fn (array $task): array => [...$task, 'next' => Carbon::parse($task['next'])->diffForHumans()], ScheduledTasks::all());

        return ['heartbeat' => app(Heartbeat::class)->last()?->diffForHumans(), 'tasks' => $tasks];
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
};
