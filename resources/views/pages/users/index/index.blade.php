<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:subheading size="lg" class="mb-6">{{ __('Find people, check their roles and manage their accounts.') }}</flux:subheading>
        </div>

        @if ($this->canInvite)
            <flux:button variant="primary" icon="envelope" :href="route('users.invite')" wire:navigate data-test="invite-user-button">
                {{ __('Invite user') }}
            </flux:button>
        @endif
    </div>
    <flux:separator variant="subtle" class="mb-6" />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-64">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or email')" :aria-label="__('Search by name or email')" />
        </div>

        <div class="w-full sm:w-44">
            <flux:select wire:model.live="status" :aria-label="__('Status')">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (\App\Enums\UserStatus::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="w-full sm:w-44">
            <flux:select wire:model.live="role" :aria-label="__('Role')">
                <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
                @foreach ($this->roleOptions as $roleId => $label)
                    <flux:select.option :value="$roleId">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->central)
            <div class="w-full sm:w-52">
                <flux:select wire:model.live="organisation" :aria-label="__('Organisation')">
                    <flux:select.option value="">{{ __('All organisations') }}</flux:select.option>
                    @foreach ($this->organisationOptions as $organisationId => $organisationName)
                        <flux:select.option :value="$organisationId">{{ $organisationName }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <flux:spacer />

        @if (Skv::hasFluxPro())
            <flux:tabs wire:model.live="view" variant="segmented" size="sm">
                <flux:tab name="active">{{ __('Active') }}</flux:tab>
                <flux:tab name="archived">{{ __('Archived') }}</flux:tab>
            </flux:tabs>
        @else
            <flux:radio.group wire:model.live="view" variant="segmented">
                <flux:radio value="active" :label="__('Active')" />
                <flux:radio value="archived" :label="__('Archived')" />
            </flux:radio.group>
        @endif
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="x-circle" class="mb-4" :heading="$errors->first()" />
    @endif

    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            @if ($this->central)
                <flux:table.column>{{ __('Organisation') }}</flux:table.column>
            @endif
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->users as $user)
                @php($abilities = $this->rowAbilities[$user->id] ?? ['view' => false, 'update' => false, 'updateStatus' => false, 'delete' => false, 'restore' => false, 'impersonate' => false])

                <flux:table.row :key="$user->id" data-test="user-row">
                    <flux:table.cell variant="strong">
                        @if ($user->status === \App\Enums\UserStatus::PendingActivation)
                            <flux:link :href="route('invitations.pending')" wire:navigate data-test="pending-user-link-{{ $user->id }}">{{ $user->name }}</flux:link>
                        @elseif ($abilities['view'] && $this->view !== 'archived')
                            <flux:link :href="route('users.edit', ['user' => $user->id])" wire:navigate>{{ $user->name }}</flux:link>
                        @else
                            <div>{{ $user->name }}</div>
                        @endif
                        <flux:text size="sm">{{ $user->email }}</flux:text>
                    </flux:table.cell>

                    @if ($this->central)
                        <flux:table.cell>
                            {{ $user->organisation_id === null ? __('Platform') : ($this->organisationNames[$user->organisation_id] ?? '') }}
                        </flux:table.cell>
                    @endif

                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @if ($user->isSuperAdmin())
                                <flux:badge size="sm" color="violet">{{ \App\Authorization\Role::SuperAdmin->label() }}</flux:badge>
                            @endif
                            @foreach ($this->rolesByUser[$user->id] ?? [] as $roleLabel)
                                <flux:badge size="sm">{{ $roleLabel }}</flux:badge>
                            @endforeach
                        </div>
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge size="sm" :color="$user->status->color()">{{ $user->status->label() }}</flux:badge>
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        @if ($this->view === 'archived')
                            @if ($abilities['restore'])
                                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="restore({{ $user->id }})" data-test="restore-user-button">
                                    {{ __('Restore') }}
                                </flux:button>
                            @endif
                        @elseif ($abilities['delete'] && $user->status !== \App\Enums\UserStatus::PendingActivation)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $user->id }})" :aria-label="__('Delete :name', ['name' => $user->name])" data-test="delete-user-button" />
                        @endif

                        @if ($this->view !== 'archived' && $abilities['impersonate'])
                            <flux:button size="sm" variant="ghost" icon="user-circle" wire:click="confirmImpersonation({{ $user->id }})" data-test="impersonate-{{ $user->id }}">
                                {{ __('Log in as this user') }}
                            </flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell :colspan="$this->central ? 5 : 4">{{ __('No users found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="delete-user-modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Delete user') }}</flux:heading>
                <flux:text>{{ __(':name can no longer sign in. You can restore the account from the archived view.', ['name' => $deletingUserName]) }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeDeleteModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-user-button">{{ __('Delete user') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @if (\App\Tenancy\Auth\Impersonation::enabled())
        <flux:modal name="impersonation-reason-modal" class="max-w-md md:min-w-md" @close="closeImpersonationModal" wire:model="showImpersonationModal" data-test="impersonation-reason-modal">
            <form wire:submit="startImpersonation" class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Log in as :name', ['name' => $impersonatingUserName]) }}</flux:heading>
                    <flux:text>{{ __('Give a reason. It is recorded in the activity log.') }}</flux:text>
                </div>

                <flux:textarea wire:model="impersonationReason" :label="__('Reason')" rows="3" maxlength="500" required />
                <flux:error name="reason" />

                <div class="flex justify-end gap-3">
                    <flux:button variant="outline" wire:click="closeImpersonationModal">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="start-impersonating">{{ __('Continue as this user') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
