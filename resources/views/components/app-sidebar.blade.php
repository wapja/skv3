@php($navigation = \App\Support\Navigation\Navigation::for(auth()->user()))

<flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:sidebar.header>
        <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
        <flux:sidebar.collapse />
    </flux:sidebar.header>

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
    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

    <flux:spacer />

    <livewire:user-menu variant="header" />
</flux:header>
