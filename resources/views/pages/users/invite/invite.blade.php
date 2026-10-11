<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('Invite user') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('We email a link with which the new user chooses a name and password.') }}</flux:subheading>
    <flux:separator variant="subtle" />

    <form wire:submit="invite" class="my-6 w-full space-y-6">
        @unless (\App\Tenancy\Facades\Tenant::check())
            <div class="space-y-2">
                <flux:select wire:model.live="organisationId" :label="__('Organisation')" :placeholder="__('Choose an organisation')" data-test="invite-organisation">
                    @foreach ($this->organisationOptions as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="organisation" />
            </div>
        @endunless

        <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="off" />

        <flux:select wire:model="locale" :label="__('Language')">
            @foreach (\App\Enums\Locale::cases() as $option)
                <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:checkbox.group wire:model="roles" :label="__('Roles')" data-test="invite-roles">
            @foreach ($this->roleOptions as $id => $label)
                <flux:checkbox :value="$id" :label="$label" />
            @endforeach
        </flux:checkbox.group>

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit" data-test="send-invitation-button">{{ __('Send invitation') }}</flux:button>
            <flux:button variant="ghost" :href="route($this->returnRoute)" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
