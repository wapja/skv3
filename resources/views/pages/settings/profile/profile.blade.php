<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />
                <flux:text class="mt-2 text-sm">{{ __('A new address is used after you confirm it through the link we email to it.') }}</flux:text>
            </div>

            <flux:select wire:model="locale" :label="__('Language')" data-test="profile-language">
                @foreach (\App\Enums\Locale::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->nativeName() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        @if ($canManageAvatar)
            <div class="my-6 w-full space-y-6" data-test="profile-photo">
                <flux:separator variant="subtle" />

                <div>
                    <flux:heading level="3">{{ __('Profile photo') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('JPEG, PNG or WebP, at most 2 MB and 4096 × 4096 pixels. We crop it to a square.') }}</flux:text>
                </div>

                <div class="flex items-center gap-4">
                    <flux:avatar size="xl" :src="$this->avatarUrl" :name="$name" :initials="$this->initials" data-test="profile-photo-preview" />

                    @if ($this->avatarUrl !== null)
                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeAvatar" data-test="remove-avatar-button">
                            {{ __('Remove photo') }}
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveAvatar" class="space-y-4">
                    @if (Skv::hasFluxPro())
                        <flux:file-upload wire:model="avatar" :label="__('New photo')">
                            <flux:file-upload.dropzone :heading="__('Drop a photo here or click to choose one')" :text="__('JPEG, PNG or WebP, at most 2 MB')" inline />
                        </flux:file-upload>

                        @if ($avatar !== null)
                            <flux:file-item :heading="$avatar->getClientOriginalName()" :size="$avatar->getSize()" icon="photo" />
                        @endif
                    @else
                        <flux:input type="file" accept="image/jpeg,image/png,image/webp" wire:model="avatar" :label="__('New photo')" />
                    @endif

                    <flux:button variant="primary" type="submit" data-test="save-avatar-button">{{ __('Save photo') }}</flux:button>
                </form>
            </div>
        @endif
    </x-pages::settings.layout>
</section>
