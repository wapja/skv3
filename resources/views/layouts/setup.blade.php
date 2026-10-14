@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-accent="{{ \App\Support\Branding::accent() }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />

        <title>{{ filled($title) ? __((string) $title).' - ' : '' }}{{ __('Setup') }}</title>

        {{-- §5.3.4: no Vite and nothing from public/build. The committed stylesheet is inlined; Livewire and Flux are served through PHP routes. --}}
        <style>{!! file_get_contents(resource_path('setup/setup.css')) !!}</style>

        @fluxAppearance
        @livewireStyles
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <main class="mx-auto w-full max-w-2xl px-6 py-12">
            {{ $slot }}
        </main>

        @livewireScripts
        @fluxScripts
    </body>
</html>
