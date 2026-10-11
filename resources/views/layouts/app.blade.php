<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-impersonation-banner />

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
