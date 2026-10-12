<div class="flex flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>

    @if ($this->checklist !== [])
        <flux:card data-test="onboarding-checklist" class="space-y-3">
            <flux:heading size="lg">{{ __('Get started') }}</flux:heading>
            <ul class="space-y-2">
                @foreach ($this->checklist as $item)
                    <li class="flex items-center gap-2" data-test="checklist-{{ $item['key'] }}">
                        @if ($item['done'])
                            <flux:icon.check-circle variant="mini" class="text-lime-600" />
                        @else
                            <flux:icon.minus-circle variant="mini" class="text-zinc-400" />
                        @endif
                        @if ($item['url'] !== null && ! $item['done'])
                            <flux:link :href="$item['url']" wire:navigate>{{ $item['label'] }}</flux:link>
                        @else
                            <span @class(['line-through text-zinc-500' => $item['done']])>{{ $item['label'] }}</span>
                        @endif
                        @if ($item['key'] === 'branding' && ! $item['done'])
                            <flux:text size="sm">{{ __('Set BRANDING_ACCENT or BRANDING_LOGO in .env.') }}</flux:text>
                        @endif
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <flux:card data-test="organisation-card">
            <flux:subheading>{{ __('Organisation') }}</flux:subheading>
            <flux:heading size="lg">{{ $this->organisationName ?? __('Platform') }}</flux:heading>
        </flux:card>

        <flux:card data-test="roles-card">
            <flux:subheading>{{ __('Your roles') }}</flux:subheading>
            <div class="mt-2 flex flex-wrap gap-2">
                @forelse ($this->roleLabels as $label)
                    <flux:badge>{{ $label }}</flux:badge>
                @empty
                    <flux:text>{{ __('No roles assigned') }}</flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>

    @if ($this->stats !== [])
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->stats as $stat)
                <a href="{{ $stat['url'] }}" wire:navigate class="block">
                    <flux:card data-test="stat-{{ $stat['key'] }}">
                        <flux:subheading>{{ $stat['label'] }}</flux:subheading>
                        <flux:heading size="xl">{{ $stat['value'] }}</flux:heading>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif

    @if (Skv::hasFluxPro())
        @if ($this->loginTrend !== null)
            <flux:card data-test="login-trend" class="space-y-3">
                <flux:heading size="lg">{{ __('Sign-ins over the last 14 days') }}</flux:heading>
                <flux:chart :value="$this->loginTrend" class="aspect-3/1">
                    <flux:chart.svg>
                        <flux:chart.line field="logins" class="text-accent" />
                        <flux:chart.axis axis="x" field="date"><flux:chart.axis.line /><flux:chart.axis.tick /></flux:chart.axis>
                        <flux:chart.axis axis="y"><flux:chart.axis.grid /><flux:chart.axis.tick /></flux:chart.axis>
                        <flux:chart.cursor />
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="date" />
                        <flux:chart.tooltip.value field="logins" :label="__('Sign-ins')" />
                    </flux:chart.tooltip>
                </flux:chart>
            </flux:card>
        @endif
    @endif

    @if ($this->recentActivity !== null)
        <flux:card data-test="recent-activity" class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Recent activity') }}</flux:heading>
                <flux:link :href="route('activity.index')" wire:navigate>{{ __('View all') }}</flux:link>
            </div>
            @forelse ($this->recentActivity as $entry)
                <div class="flex items-center justify-between gap-4 text-sm" data-test="recent-activity-row">
                    <span>{{ $entry['event'] }}@if ($entry['causer']) · {{ $entry['causer'] }}@endif</span>
                    <flux:text size="sm">{{ $entry['when'] }}</flux:text>
                </div>
            @empty
                <flux:text>{{ __('No activity yet.') }}</flux:text>
            @endforelse
        </flux:card>
    @endif
</div>
