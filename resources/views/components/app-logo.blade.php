@props([
    'sidebar' => false,
])

@php
    $logoUrl = \App\Support\Branding::logoUrl();
@endphp

@if($sidebar)
    <flux:sidebar.brand :name="\App\Support\Branding::name()" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            @if ($logoUrl !== null)
                <img src="{{ $logoUrl }}" alt="" class="size-5">
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="\App\Support\Branding::name()" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            @if ($logoUrl !== null)
                <img src="{{ $logoUrl }}" alt="" class="size-5">
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:brand>
@endif
