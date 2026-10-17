<section class="w-full">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $this->target->name }}</flux:heading>
            <flux:subheading size="lg">{{ $this->target->email }}</flux:subheading>
        </div>

        <flux:button :href="route('users.index')" variant="ghost" icon="arrow-left" wire:navigate>{{ __('All users') }}</flux:button>
    </div>

    <flux:separator variant="subtle" />

    @if ($draftRestored)
        <flux:callout icon="information-circle" class="mt-6" :heading="__('Your unsaved changes are back. Save again to apply them.')" />
    @endif

    <div class="mt-6 grid gap-10 lg:grid-cols-2">
        <section class="space-y-6">
            <flux:heading size="lg" level="2">{{ __('Account') }}</flux:heading>

            @if ($this->abilities['update'])
                <form wire:submit="saveAccount" class="space-y-6">
                    <flux:input wire:model="name" :label="__('Name')" type="text" required autocomplete="off" />

                    @if ($this->abilities['ownAccount'])
                        <flux:input :value="$this->target->email" :label="__('Email address')" type="email" readonly />
                        <flux:text class="text-sm">
                            {{ __('Change your own address on your profile page.') }}
                            <flux:link :href="route('profile.edit')" wire:navigate data-test="own-profile-link">{{ __('Go to your profile') }}</flux:link>
                        </flux:text>
                    @elseif ($this->abilities['pending'])
                        <flux:input :value="$this->target->email" :label="__('Email address')" type="email" readonly />
                        <flux:text class="text-sm">{{ __('Cancel the invitation and invite the new address.') }}</flux:text>
                    @else
                        <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="off" />
                        <flux:text class="text-sm">{{ __('A new address asks for your password first, and the old address gets a notice.') }}</flux:text>
                    @endif

                    @if ($this->abilities['updateStatus'])
                        <flux:select wire:model="status" :label="__('Status')">
                            <flux:select.option :value="\App\Enums\UserStatus::Active->value">{{ \App\Enums\UserStatus::Active->label() }}</flux:select.option>
                            <flux:select.option :value="\App\Enums\UserStatus::Disabled->value">{{ \App\Enums\UserStatus::Disabled->label() }}</flux:select.option>
                        </flux:select>
                    @else
                        <div class="space-y-2">
                            <flux:text size="sm">{{ __('Status') }}</flux:text>
                            <flux:badge size="sm" :color="$this->target->status->color()">{{ $this->target->status->label() }}</flux:badge>
                        </div>
                    @endif

                    <flux:button variant="primary" type="submit" data-test="save-account-button">{{ __('Save') }}</flux:button>
                </form>
            @else
                <dl class="space-y-4">
                    <div>
                        <dt><flux:text size="sm">{{ __('Name') }}</flux:text></dt>
                        <dd><flux:heading>{{ $this->target->name }}</flux:heading></dd>
                    </div>
                    <div>
                        <dt><flux:text size="sm">{{ __('Email address') }}</flux:text></dt>
                        <dd><flux:heading>{{ $this->target->email }}</flux:heading></dd>
                    </div>
                    <div>
                        <dt><flux:text size="sm">{{ __('Status') }}</flux:text></dt>
                        <dd><flux:badge size="sm" :color="$this->target->status->color()">{{ $this->target->status->label() }}</flux:badge></dd>
                    </div>
                </dl>
            @endif
        </section>

        <section class="space-y-6">
            <flux:heading size="lg" level="2">{{ __('Roles') }}</flux:heading>

            @if ($this->abilities['updateRoles'])
                <form wire:submit="saveRoles" class="space-y-6">
                    @if (Skv::hasFluxPro())
                        <flux:select variant="listbox" multiple searchable indicator="checkbox" wire:model="roles">
                            @foreach ($this->roleOptions as $roleId => $label)
                                <flux:select.option :value="(string) $roleId">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:checkbox.group wire:model="roles">
                            @foreach ($this->roleOptions as $roleId => $label)
                                <flux:checkbox :value="(string) $roleId" :label="$label" />
                            @endforeach
                        </flux:checkbox.group>
                    @endif

                    <flux:error name="roles" />

                    <flux:button variant="primary" type="submit" data-test="save-roles-button">{{ __('Save roles') }}</flux:button>
                </form>
            @else
                @if ($this->abilities['pending'])
                    <flux:callout icon="information-circle" :heading="__('Cancel the invitation and invite again with the new roles.')" data-test="pending-roles-callout" />
                @endif

                <div class="flex flex-wrap gap-1">
                    @if ($this->target->isSuperAdmin())
                        <flux:badge size="sm" :color="\App\Authorization\Role::SuperAdmin->color()">{{ \App\Authorization\Role::SuperAdmin->label() }}</flux:badge>
                    @endif
                    @forelse (array_intersect_key($this->roleOptions, array_flip(array_map('intval', $roles))) as $label)
                        <flux:badge size="sm">{{ $label }}</flux:badge>
                    @empty
                        @unless ($this->target->isSuperAdmin())
                            <flux:text>{{ __('No roles') }}</flux:text>
                        @endunless
                    @endforelse
                </div>
            @endif
        </section>
    </div>

    @if ($this->abilities['updateSuperAdmin'])
        <section class="mt-10 max-w-lg space-y-4">
            <flux:heading size="lg" level="2">{{ __('Super admin') }}</flux:heading>

            <form wire:submit="saveSuperAdmin" class="space-y-4">
                <flux:switch wire:model="isSuperAdmin" :label="__('Super admin')" :description="__('Super admins manage every organisation. Changing this asks for your password.')" />

                <flux:error name="is_super_admin" />

                <flux:button variant="primary" type="submit" data-test="save-super-admin-button">{{ __('Save') }}</flux:button>
            </form>
        </section>
    @endif
</section>
