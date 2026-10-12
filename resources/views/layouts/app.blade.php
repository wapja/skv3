<x-layouts::base :title="$title ?? null">
    <x-app-sidebar />

    <flux:main>
        <x-impersonation-banner />

        {{ $slot }}
    </flux:main>
</x-layouts::base>
