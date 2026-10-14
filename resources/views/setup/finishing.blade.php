{{-- RL-6: shown while the detached Herd step runs; the controller's Refresh header reloads it every 2 seconds. --}}
<x-layouts::setup>
    <div data-test="setup-finishing" class="space-y-3">
        <flux:heading size="xl" level="1">{{ __('Finishing the Herd site…') }}</flux:heading>
        <flux:text>{{ __('Herd may restart; this page continues by itself.') }}</flux:text>
    </div>
</x-layouts::setup>
