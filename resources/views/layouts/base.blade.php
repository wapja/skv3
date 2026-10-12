@props(['title' => null])

@php
    $user = auth()->user();
    $appearance = $user instanceof \App\Models\User ? $user->appearance : null;
    $storedAppearance = $appearance instanceof \App\Enums\Appearance && $appearance !== \App\Enums\Appearance::System ? $appearance->value : null;
    $pageTitle = filled($title) ? __((string) $title) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $appearance === \App\Enums\Appearance::Dark])>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        <title>{{ $pageTitle !== null ? $pageTitle.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- DEC-12: Flux keeps the choice per origin; the user's stored value is written first, so every subdomain agrees and nothing flashes. --}}
        <script>
            (() => {
                try {
                    const stored = @js($storedAppearance);
                    stored === null ? localStorage.removeItem('flux.appearance') : localStorage.setItem('flux.appearance', stored);
                } catch (error) {}
            })();
        </script>
        @fluxAppearance
    </head>
    <body {{ $attributes->class('min-h-screen bg-white antialiased dark:bg-zinc-800') }}>
        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{-- The flash→toast bridge (§6.5): a session('status') message becomes a Flux toast. --}}
        @if (is_string(session('status')) && session('status') !== '')
            <div hidden data-flash-toast x-data x-init="$nextTick(() => $flux.toast({ text: {{ \Illuminate\Support\Js::from(session('status')) }} }))"></div>
        @endif

        @fluxScripts
    </body>
</html>
