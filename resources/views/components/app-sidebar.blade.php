@php($navigation = \App\Support\Navigation\Navigation::for(auth()->user()))

<flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:sidebar.header>
        <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
        {{-- A dynamic tooltip keeps Blaze from folding the button, which would fix __() to the compiling locale. --}}
        <flux:sidebar.collapse :tooltip="__('Toggle sidebar')" />
    </flux:sidebar.header>

    <x-command-palette />

    <flux:sidebar.nav>
        @foreach ($navigation as $section)
            <flux:sidebar.group :heading="$section['group']->label()" class="grid">
                @foreach ($section['items'] as $item)
                    <flux:sidebar.item
                        :icon="$item->icon"
                        :href="$item->url()"
                        :current="$item->isCurrent()"
                        wire:navigate
                        data-test="nav-{{ $item->route }}"
                    >
                        {{ $item->label() }}
                    </flux:sidebar.item>
                @endforeach
            </flux:sidebar.group>
        @endforeach
    </flux:sidebar.nav>

    <flux:spacer />

    <livewire:user-menu variant="sidebar" class="hidden lg:block" />
</flux:sidebar>

<!-- Mobile User Menu -->
<flux:header class="lg:hidden">
    {{-- flux:sidebar.toggle with a runtime label: Blaze folds that component with __() fixed at compile time. --}}
    <flux:button
        class="shrink-0 lg:hidden"
        variant="subtle"
        square
        icon="bars-2"
        inset="left"
        x-data
        x-on:click="$dispatch('flux-sidebar-toggle')"
        :aria-label="__('Toggle sidebar')"
        data-flux-sidebar-toggle
    />

    <flux:spacer />

    <livewire:user-menu variant="header" />
</flux:header>
