<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('New organisation') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Create the organisation and invite its first admin.') }}</flux:subheading>
    <flux:separator variant="subtle" />

    <form wire:submit="create" class="my-6 space-y-6">
        <flux:input wire:model.live.debounce.300ms="name" :label="__('Name')" required autofocus data-test="organisation-name" />

        <div class="space-y-2">
            <flux:input
                wire:model.live.debounce.300ms="slug"
                :label="__('Address')"
                :description="__('Lower-case letters, digits and hyphens, 3 to 63 characters.')"
                required
                data-test="organisation-slug"
            />

            @if ($slug !== '')
                <flux:text data-test="{{ $this->slugStatus['available'] ? 'slug-available' : 'slug-unavailable' }}">
                    <span class="font-medium">{{ $this->slugStatus['host'] }}</span>
                    — {{ $this->slugStatus['available'] ? __('This address is available.') : $this->slugStatus['message'] }}
                </flux:text>
            @endif
        </div>

        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        <flux:separator variant="subtle" />

        <flux:heading size="lg">{{ __('First admin') }}</flux:heading>

        <flux:input wire:model="adminEmail" :label="__('Email address')" type="email" required autocomplete="off" data-test="admin-email" />

        <flux:select wire:model="adminLocale" :label="__('Language')" data-test="admin-locale">
            @foreach (\App\Enums\Locale::cases() as $locale)
                <flux:select.option :value="$locale->value">{{ $locale->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex justify-end gap-2">
            <flux:button :href="route('organisations.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="create-organisation-button">{{ __('Create organisation') }}</flux:button>
        </div>
    </form>
</section>
