<section class="w-full space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('System status') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('See health checks, backups and the scheduler') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    {{-- §4.9: the same cached report /health-check returns. --}}
    <section class="space-y-3" data-test="system-health">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <flux:heading size="lg" level="2">{{ __('Health') }}</flux:heading>
                <flux:badge size="sm" :color="$this->health['healthy'] ? 'lime' : 'red'">
                    {{ $this->health['healthy'] ? __('Healthy') : __('Unhealthy') }}
                </flux:badge>
                <flux:text size="sm">{{ __('Checked :time', ['time' => $this->health['checked']]) }}</flux:text>
            </div>
            <flux:button size="sm" icon="arrow-path" wire:click="refreshHealth" data-test="system-refresh-health">{{ __('Refresh') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Check') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Details') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->health['rows'] as $row)
                    <flux:table.row :key="'health-'.$row['key']" data-test="system-health-row-{{ $row['key'] }}">
                        <flux:table.cell variant="strong">{{ $row['label'] }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$row['color']">{{ $row['status'] }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $row['message'] }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="space-y-3" data-test="system-backups">
        <flux:heading size="lg" level="2">{{ __('Backups') }}</flux:heading>

        @if (! $this->backups['enabled'])
            <flux:text>{{ __('The backups module is off') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Disk') }}</flux:table.column>
                    <flux:table.column>{{ __('Newest backup') }}</flux:table.column>
                    <flux:table.column>{{ __('Backups') }}</flux:table.column>
                    <flux:table.column>{{ __('Used storage') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->backups['destinations'] as $destination)
                        <flux:table.row :key="'backup-'.$destination['disk']" data-test="system-backup-row-{{ $destination['disk'] }}">
                            <flux:table.cell variant="strong">{{ $destination['disk'] }}</flux:table.cell>
                            <flux:table.cell>
                                @if (! $destination['reachable'])
                                    <flux:badge size="sm" color="red">{{ __('Not reachable') }}</flux:badge>
                                @else
                                    {{ $destination['newest'] ?? __('None yet') }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $destination['count'] }}</flux:table.cell>
                            <flux:table.cell>{{ $destination['size'] ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>

    <section class="space-y-3" data-test="system-schedule">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg" level="2">{{ __('Schedule') }}</flux:heading>
            <flux:text size="sm">
                {{ $this->schedule['heartbeat'] === null ? __('The scheduler has not run yet') : __('Last heartbeat :time', ['time' => $this->schedule['heartbeat']]) }}
            </flux:text>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Task') }}</flux:table.column>
                <flux:table.column>{{ __('When') }}</flux:table.column>
                <flux:table.column>{{ __('Next run') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->schedule['tasks'] as $index => $task)
                    <flux:table.row :key="'task-'.$index">
                        <flux:table.cell variant="strong">
                            <code class="text-sm">{{ $task['task'] }}</code>
                            @if (! $task['active'])
                                <flux:badge size="sm" color="zinc">{{ __('off') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell><code class="text-sm">{{ $task['expression'] }}</code></flux:table.cell>
                        <flux:table.cell>{{ $task['next'] }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="space-y-3" data-test="system-doctor">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="lg" level="2">{{ __('Doctor') }}</flux:heading>
                <flux:text size="sm">{{ __('The checks that start no process. Run php artisan skv:doctor for the full report and its repairs.') }}</flux:text>
            </div>
            <flux:button size="sm" icon="play" wire:click="runDoctor" data-test="system-run-checks">{{ __('Run checks') }}</flux:button>
        </div>

        @if ($this->doctor !== [])
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Check') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Details') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->doctor as $row)
                        <flux:table.row :key="'doctor-'.$row['key']" data-test="system-doctor-row-{{ $row['key'] }}">
                            <flux:table.cell variant="strong">{{ $row['label'] }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$row['color']">{{ $row['status'] }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $row['message'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>
</section>
