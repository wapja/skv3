<section class="w-full max-w-3xl">
    <flux:heading size="xl" level="1">{{ __('Invite several people') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Paste addresses or upload a CSV file, check the list, then send the invitations.') }}</flux:subheading>
    <flux:separator variant="subtle" />

    @if ($summary !== null)
        <div class="my-6 space-y-6" data-test="bulk-summary">
            <flux:callout icon="paper-airplane" variant="success" :heading="__('Sent: :count', ['count' => count($summary['sent'])])" />

            @if ($summary['skipped'] !== [])
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Skipped: :count', ['count' => count($summary['skipped'])]) }}</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Email address') }}</flux:table.column>
                            <flux:table.column>{{ __('Reason') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($summary['skipped'] as $skipped)
                                <flux:table.row :key="'skipped-'.$loop->index">
                                    <flux:table.cell variant="strong">{{ $skipped['email'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $skipped['reason'] }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex items-center gap-4">
                <flux:button variant="primary" wire:click="startOver" data-test="bulk-start-over">{{ __('Invite more people') }}</flux:button>
                <flux:button variant="ghost" :href="route($this->returnRoute)" wire:navigate>{{ __('Done') }}</flux:button>
            </div>
        </div>
    @else
        <form wire:submit="check" class="my-6 w-full space-y-6">
            @unless (\App\Tenancy\Facades\Tenant::check())
                <div class="space-y-2">
                    <flux:select wire:model.live="organisationId" :label="__('Organisation')" :placeholder="__('Choose an organisation')" data-test="bulk-organisation">
                        @foreach ($this->organisationOptions as $id => $name)
                            <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endunless

            <flux:textarea
                wire:model="addresses"
                :label="__('Email addresses')"
                :description="__('One per line, or separated by commas or semicolons.')"
                rows="6"
                data-test="bulk-addresses"
            />

            @if (Skv::hasFluxPro())
                <div class="space-y-3">
                    <flux:file-upload wire:model="csv" :label="__('CSV file')" :description="__('An email column and an optional locale column (nl or en), with or without a header row; at most 512 KB.')">
                        <flux:file-upload.dropzone :heading="__('Drop a CSV file here or click to browse')" :text="__('CSV or text, up to 512 KB')" inline />
                    </flux:file-upload>

                    @if ($csv)
                        <flux:file-item :heading="$csv->getClientOriginalName()" :size="$csv->getSize()" icon="document-text">
                            <x-slot name="actions">
                                <flux:file-item.remove wire:click="removeCsv" :aria-label="__('Remove file')" />
                            </x-slot>
                        </flux:file-item>
                    @endif
                </div>
            @else
                <flux:input
                    type="file"
                    wire:model="csv"
                    accept=".csv,.txt,text/csv,text/plain"
                    :label="__('CSV file')"
                    :description="__('An email column and an optional locale column (nl or en), with or without a header row; at most 512 KB.')"
                    data-test="bulk-csv"
                />
            @endif

            <flux:select wire:model="locale" :label="__('Language')" :description="__('For every address that names no language.')">
                @foreach (\App\Enums\Locale::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if (Skv::hasFluxPro())
                <flux:select variant="listbox" multiple searchable indicator="checkbox" wire:model="roles" :label="__('Roles')" data-test="bulk-roles">
                    @foreach ($this->roleOptions as $id => $label)
                        <flux:select.option :value="(string) $id">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:checkbox.group wire:model="roles" :label="__('Roles')" data-test="bulk-roles">
                    @foreach ($this->roleOptions as $id => $label)
                        <flux:checkbox :value="$id" :label="$label" />
                    @endforeach
                </flux:checkbox.group>
            @endif

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="bulk-check">{{ __('Check the list') }}</flux:button>
                <flux:button variant="ghost" :href="route($this->returnRoute)" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>

        @if ($rows !== [])
            <div class="space-y-4" data-test="bulk-preview">
                <flux:heading size="lg">{{ __('Ready: :ready of :total', ['ready' => $this->readyCount, 'total' => count($rows)]) }}</flux:heading>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Email address') }}</flux:table.column>
                        <flux:table.column>{{ __('Language') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($rows as $row)
                            <flux:table.row :key="'row-'.$loop->index">
                                <flux:table.cell variant="strong">{{ $row['email'] }}</flux:table.cell>
                                <flux:table.cell>{{ \App\Enums\Locale::tryFrom($row['locale'])?->label() ?? $row['locale'] }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($row['reason'] === null)
                                        <flux:badge size="sm" color="green">{{ __('Ready') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ $row['reason'] }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                <flux:button variant="primary" icon="paper-airplane" wire:click="send" :disabled="$this->readyCount === 0" data-test="bulk-send">
                    {{ __('Send invitations (:count)', ['count' => $this->readyCount]) }}
                </flux:button>
            </div>
        @endif
    @endif
</section>
