<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Activity log') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('See who did what and when') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <flux:input type="date" wire:model.live="from" :label="__('From')" data-test="filter-from" />
        <flux:input type="date" wire:model.live="until" :label="__('Until')" data-test="filter-until" />

        <flux:select wire:model.live="event" :label="__('Action')" data-test="filter-event">
            <flux:select.option value="">{{ __('All actions') }}</flux:select.option>
            @foreach ($this->eventOptions as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="subject" :label="__('Subject')" data-test="filter-subject">
            <flux:select.option value="">{{ __('All subjects') }}</flux:select.option>
            @foreach ($this->subjectOptions as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="causer" :label="__('Done by')" data-test="filter-causer">
            <flux:select.option value="">{{ __('Everyone') }}</flux:select.option>
            @foreach ($this->causerOptions as $id => $name)
                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->isCentral)
            <flux:select wire:model.live="organisation" :label="__('Organisation')" data-test="filter-organisation">
                <flux:select.option value="">{{ __('All organisations') }}</flux:select.option>
                @foreach ($this->organisationOptions as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <flux:table :paginate="$this->activities">
        <flux:table.columns>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Action') }}</flux:table.column>
            <flux:table.column>{{ __('Done by') }}</flux:table.column>
            <flux:table.column>{{ __('Subject') }}</flux:table.column>
            @if ($this->isCentral)
                <flux:table.column>{{ __('Organisation') }}</flux:table.column>
            @endif
            <flux:table.column>{{ __('Changes') }}</flux:table.column>
            <flux:table.column>{{ __('Reason or details') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->rows as $row)
                <flux:table.row :key="'activity-'.$row['id']">
                    <flux:table.cell class="whitespace-nowrap">{{ $row['when'] }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ $row['event'] }}</flux:table.cell>
                    <flux:table.cell>{{ $row['causer'] }}</flux:table.cell>
                    <flux:table.cell>{{ $row['subject'] }}</flux:table.cell>
                    @if ($this->isCentral)
                        <flux:table.cell>{{ $row['organisation'] }}</flux:table.cell>
                    @endif
                    <flux:table.cell>
                        @foreach ($row['changes'] as $change)
                            <div class="text-sm">
                                <span class="font-medium">{{ $change['attribute'] }}</span>: {{ $change['old'] }} → {{ $change['new'] }}
                            </div>
                        @endforeach
                    </flux:table.cell>
                    <flux:table.cell>{{ $row['details'] }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="eye" wire:click="showDetail({{ $row['id'] }})" data-test="activity-detail-button">
                            {{ __('Details') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8">{{ __('No activity yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="activity-detail" variant="flyout" class="md:w-[32rem]" wire:model="detailOpen" @close="closeDetail">
        @if ($this->detail !== null)
            @php($detail = $this->detail)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Activity details') }}</flux:heading>
                    <flux:text class="mt-1">{{ $detail['when'] }}</flux:text>
                </div>

                <dl class="grid grid-cols-3 gap-x-4 gap-y-2 text-sm">
                    <dt class="font-medium">{{ __('Action') }}</dt>
                    <dd class="col-span-2">{{ $detail['event'] }}</dd>
                    <dt class="font-medium">{{ __('Event key') }}</dt>
                    <dd class="col-span-2 font-mono">{{ $detail['event_key'] }}</dd>
                    <dt class="font-medium">{{ __('Done by') }}</dt>
                    <dd class="col-span-2">{{ $detail['causer'] }}</dd>
                    <dt class="font-medium">{{ __('Subject') }}</dt>
                    <dd class="col-span-2">{{ $detail['subject'] }}</dd>
                    @if ($detail['organisation'] !== null)
                        <dt class="font-medium">{{ __('Organisation') }}</dt>
                        <dd class="col-span-2">{{ $detail['organisation'] }}</dd>
                    @endif
                    <dt class="font-medium">{{ __('Reason or details') }}</dt>
                    <dd class="col-span-2">{{ $detail['details'] ?? '—' }}</dd>
                    <dt class="font-medium">{{ __('IP address') }}</dt>
                    <dd class="col-span-2 font-mono">{{ $detail['ip_address'] ?? '—' }}</dd>
                    <dt class="font-medium">{{ __('User agent') }}</dt>
                    <dd class="col-span-2 break-all">{{ $detail['user_agent'] ?? '—' }}</dd>
                </dl>

                @if ($detail['changes'] !== [])
                    <div class="space-y-2">
                        <flux:heading size="sm">{{ __('Changes') }}</flux:heading>
                        @foreach ($detail['changes'] as $change)
                            <div class="text-sm">
                                <span class="font-medium">{{ $change['attribute'] }}</span>: {{ $change['old'] }} → {{ $change['new'] }}
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-2">
                    <flux:heading size="sm">{{ __('Properties') }}</flux:heading>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ $detail['properties'] }}</pre>
                </div>
            </div>
        @endif
    </flux:modal>
</section>
