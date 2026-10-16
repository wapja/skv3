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

        {{-- M5: with HTTPS off, passkeys are off already and nothing more is switched off; without a command there is nothing to run. --}}
        <flux:text>
            @if ($command !== null)
                {{ $https ? __('Run it in a terminal, then reload this page, or continue without HTTPS (passkeys are then switched off).') : __('Run it in a terminal, then reload this page, or continue and run it later.') }}
            @else
                {{ $https ? __('There is no Herd command to run. Continue without HTTPS (passkeys are then switched off).') : __('There is no Herd command to run. Continue to finish setting up.') }}
            @endif
        </flux:text>

        <div class="flex items-center gap-3">
            <flux:button :href="$reloadUrl">{{ __('Reload') }}</flux:button>
            <flux:button variant="primary" :href="$continueUrl" data-test="setup-continue-without-https">{{ $https ? __('Continue without HTTPS') : __('Continue') }}</flux:button>
        </div>
    </div>
</x-layouts::setup>
