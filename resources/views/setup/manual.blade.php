{{-- RL-6, RL-7, OD-2: the Herd step gave no answer within the poll's 60 seconds; $command is the Herd command left to run, or null. --}}
<x-layouts::setup>
    <div data-test="setup-manual-herd" class="space-y-4">
        <flux:heading size="xl" level="1">{{ $https ? __('Herd did not confirm HTTPS in time.') : __('Herd did not confirm the site in time.') }}</flux:heading>

        @if ($command !== null)
            <div class="flex items-center gap-2" x-data>
                <code class="rounded bg-zinc-100 px-2 py-1 text-sm dark:bg-zinc-800">{{ $command }}</code>
                <flux:button size="xs" x-on:click="navigator.clipboard?.writeText(@js($command)).catch(() => {})">{{ __('Copy') }}</flux:button>
            </div>
        @endif

        <flux:text>{{ __('Run it in a terminal, then reload this page, or continue without HTTPS (passkeys are then switched off).') }}</flux:text>

        <div class="flex items-center gap-3">
            <flux:button :href="$reloadUrl">{{ __('Reload') }}</flux:button>
            <flux:button variant="primary" :href="$continueUrl" data-test="setup-continue-without-https">{{ __('Continue without HTTPS') }}</flux:button>
        </div>
    </div>
</x-layouts::setup>
