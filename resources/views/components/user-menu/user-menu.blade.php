{{-- The sidebar menu is mounted on every app page: it alone saves $flux.appearance, once per change. --}}
<div {{ $attributes }} @if ($variant === 'sidebar') x-data x-init="$watch('$flux.appearance', value => $wire.saveAppearance(value))" @endif>
    <flux:dropdown :position="$variant === 'sidebar' ? 'bottom' : 'top'" :align="$variant === 'sidebar' ? 'start' : 'end'">
        @if ($variant === 'sidebar')
            <flux:sidebar.profile :name="$user->name" :initials="$user->initials()" icon:trailing="chevrons-up-down" data-test="sidebar-menu-button" />
        @else
            <flux:profile :initials="$user->initials()" icon-trailing="chevron-down" />
        @endif

        <flux:menu>
            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                <flux:avatar :name="$user->name" :initials="$user->initials()" />
                <div class="grid flex-1 text-start text-sm leading-tight">
                    <flux:heading class="truncate">{{ $user->name }}</flux:heading>
                    <flux:text class="truncate">{{ $user->email }}</flux:text>
                </div>
            </div>

            <flux:menu.separator />

            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>

            <flux:menu.separator />

            <flux:menu.heading>{{ __('Language') }}</flux:menu.heading>
            <flux:menu.radio.group data-test="user-menu-language">
                @foreach ($locales as $locale)
                    <flux:menu.radio :value="$locale->value" :checked="$user->locale === $locale" wire:click="setLocale('{{ $locale->value }}')">
                        {{ $locale->nativeName() }}
                    </flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>

            <flux:menu.separator />

            <flux:menu.heading>{{ __('Appearance') }}</flux:menu.heading>
            <flux:menu.radio.group x-data x-model="$flux.appearance" data-test="user-menu-appearance">
                @foreach (\App\Enums\Appearance::cases() as $appearance)
                    <flux:menu.radio :value="$appearance->value">{{ $appearance->label() }}</flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>

            <flux:menu.separator />

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu>
    </flux:dropdown>
</div>
