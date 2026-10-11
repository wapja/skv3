<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Organisations') }}</flux:heading>
            <flux:subheading size="lg">{{ __('Organisations and their addresses') }}</flux:subheading>
        </div>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="flex flex-wrap items-center gap-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search by name or address')"
            class="max-w-sm"
            data-test="organisations-search"
        />

        @if ($this->onCentralHost)
            <flux:radio.group wire:model.live="view" variant="segmented" data-test="organisations-view">
                <flux:radio value="active" :label="__('Active')" />
                <flux:radio value="archived" :label="__('Archived')" />
            </flux:radio.group>
        @endif
    </div>

    @error('email')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" :heading="$message" />
    @enderror
    @error('organisation')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" :heading="$message" />
    @enderror

    <flux:table :paginate="$this->organisations" class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Address') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->organisations as $organisation)
                <flux:table.row :key="$organisation->id" data-test="organisation-row-{{ $organisation->id }}">
                    <flux:table.cell variant="strong">{{ $organisation->name }}</flux:table.cell>
                    <flux:table.cell>{{ \App\Tenancy\Hosts::tenant($organisation->slug) }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @if ($organisation->trashed())
                                @can('restore', $organisation)
                                    <flux:button size="sm" icon="arrow-uturn-left" wire:click="restore({{ $organisation->id }})" data-test="restore-organisation-{{ $organisation->id }}">
                                        {{ __('Restore') }}
                                    </flux:button>
                                @endcan
                                @can('forceDelete', $organisation)
                                    <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmPurge({{ $organisation->id }})" data-test="purge-organisation-{{ $organisation->id }}">
                                        {{ __('Purge') }}
                                    </flux:button>
                                @endcan
                            @else
                                @if ($this->onCentralHost)
                                    @can('view', $organisation)
                                        <flux:button size="sm" icon="arrow-top-right-on-square" wire:click="open({{ $organisation->id }})" data-test="open-organisation-{{ $organisation->id }}">
                                            {{ __('Open') }}
                                        </flux:button>
                                    @endcan
                                @endif
                                @can('delete', $organisation)
                                    <flux:button size="sm" variant="ghost" icon="archive-box" wire:click="confirmArchive({{ $organisation->id }})" data-test="archive-organisation-{{ $organisation->id }}">
                                        {{ __('Archive') }}
                                    </flux:button>
                                @endcan
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3">{{ __('No organisations found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="archive-organisation" class="max-w-md md:min-w-md">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Archive :name?', ['name' => $this->archivingOrganisation?->name ?? '']) }}</flux:heading>
                <flux:text>{{ __('The organisation and its users are archived and everyone there is signed out. You can restore it from the archived view.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="archive" data-test="confirm-archive-button">{{ __('Archive') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="purge-organisation" class="max-w-md md:min-w-md">
        <form wire:submit="purge" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Purge :name?', ['name' => $this->purgingOrganisation?->name ?? '']) }}</flux:heading>
                <flux:text>{{ __('This permanently deletes the organisation, its users, invitations and roles. The activity log is kept. This cannot be undone.') }}</flux:text>
            </div>

            <flux:input
                wire:model="purgeConfirmation"
                :label="__('Type :slug to confirm.', ['slug' => $this->purgingOrganisation?->slug ?? ''])"
                autocomplete="off"
                data-test="purge-confirmation"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="confirm-purge-button">{{ __('Purge') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
