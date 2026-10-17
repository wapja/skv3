<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('Edit organisation') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ $this->organisation->name }}</flux:subheading>
    <flux:separator variant="subtle" />

    <form wire:submit="save" class="my-6 space-y-6">
        <flux:input wire:model="name" :label="__('Name')" required data-test="organisation-name" />

        @if ($this->canChangeSlug)
            <flux:input
                wire:model="slug"
                :label="__('Address')"
                :description="__('Changing the address moves the organisation to a new host: everyone there is signed out and old links stop working.')"
                required
                data-test="organisation-slug"
            />
        @else
            <flux:input
                :value="\App\Tenancy\Hosts::tenant($this->organisation->slug)"
                :label="__('Address')"
                :description="__('Only a platform administrator can change the address.')"
                readonly
                data-test="organisation-slug-readonly"
            />
        @endif

        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        <flux:switch
            wire:model="requireSecondFactor"
            :label="__('Require a second factor')"
            :description="Skv::passkeysEnabled()
                ? __('Members must set up two-factor authentication or a passkey before they can continue. Changing this asks for your password.')
                : __('Members must set up two-factor authentication before they can continue. Changing this asks for your password.')"
            data-test="organisation-require-second-factor"
        />

        <flux:error name="organisation" />

        <div class="flex justify-end gap-2">
            <flux:button :href="route('organisations.index')" variant="ghost" wire:navigate>{{ __('Back') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="save-organisation-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
