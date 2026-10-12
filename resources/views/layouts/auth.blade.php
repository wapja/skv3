<x-layouts::base :title="$title ?? null" :flash-toast="false" class="dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
    <x-impersonation-banner :inset="false" />

    <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
        <div class="flex w-full max-w-sm flex-col gap-2">
            <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                <span class="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                    <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                </span>
                <span class="sr-only">{{ \App\Support\Branding::name() }}</span>
            </a>
            <div class="flex flex-col gap-6">
                {{ $slot }}
            </div>
            <x-language-toggle />
        </div>
    </div>
</x-layouts::base>
