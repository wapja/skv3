<x-layouts::auth :title="__('Continue as another user?')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Continue as another user?')"
            :description="__('You are signed in as :name. Continue as :other?', ['name' => $currentName, 'other' => $targetName])"
        />

        <form method="POST" action="{{ $action }}" class="flex flex-col gap-6">
            @csrf

            <flux:button variant="primary" type="submit" class="w-full" data-test="handoff-continue-button">
                {{ __('Continue as :name', ['name' => $targetName]) }}
            </flux:button>
        </form>

        <flux:button :href="route('dashboard')" variant="ghost" class="w-full" data-test="handoff-stay-button">
            {{ __('Stay signed in as :name', ['name' => $currentName]) }}
        </flux:button>
    </div>
</x-layouts::auth>
