{{-- §5.3.4, RL-5: `/` on a host the wizard cannot pin and no address to suggest (g3-10, g3-28). Never the token. --}}
<x-layouts::setup>
    <div data-test="setup-host-problem" class="space-y-4">
        <flux:heading size="xl" level="1">{{ __('The setup cannot start on this address.') }}</flux:heading>

        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$problem" />
    </div>
</x-layouts::setup>
