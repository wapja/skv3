<x-layouts::base :title="$title ?? null">
    <x-app-sidebar />

    {{-- data-test="app-main": the browser suite tells a page from an error page at the same path by it (A77). --}}
    <flux:main data-test="app-main">
        <x-impersonation-banner />

        {{ $slot }}
    </flux:main>
</x-layouts::base>
