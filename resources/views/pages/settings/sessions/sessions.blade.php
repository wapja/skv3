<section class="mt-12">
    <flux:heading>{{ __('Sessions') }}</flux:heading>
    <flux:subheading>{{ __('The devices where you are signed in') }}</flux:subheading>

    <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm">
        @if ($this->sessions !== [])
            <div class="border rounded-lg border-zinc-200 dark:border-zinc-700 overflow-hidden">
                @foreach ($this->sessions as $session)
                    <div wire:key="session-{{ $session['key'] }}" class="flex items-center gap-4 p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            @if ($session['device'] === 'mobile')
                                <flux:icon.device-phone-mobile class="size-5 text-zinc-500 dark:text-zinc-400" />
                            @elseif ($session['device'] === 'tablet')
                                <flux:icon.device-tablet class="size-5 text-zinc-500 dark:text-zinc-400" />
                            @else
                                <flux:icon.computer-desktop class="size-5 text-zinc-500 dark:text-zinc-400" />
                            @endif
                        </div>
                        <div class="space-y-1">
                            <div class="flex items-center gap-2.5">
                                <p class="font-medium tracking-tight">{{ __(':browser on :platform', ['browser' => $session['browser'] ?? __('Unknown browser'), 'platform' => $session['platform'] ?? __('Unknown platform')]) }}</p>
                                @if ($session['current'])
                                    <flux:badge size="sm" color="green">{{ __('This device') }}</flux:badge>
                                @endif
                            </div>
                            <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                @if ($session['ip'] !== null)
                                    {{ $session['ip'] }}
                                    <span class="opacity-50 mx-1">/</span>
                                @endif
                                {{ __('Last active :time', ['time' => $session['last_active']]) }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div>
            <flux:modal.trigger name="logout-other-devices">
                <flux:button variant="filled">{{ __('Log out other devices') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:modal name="logout-other-devices" class="max-w-md md:min-w-md">
        <form wire:submit="logoutOtherDevices" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Log out other devices') }}</flux:heading>
                <flux:text>{{ __('Enter your password to log out on your other devices. You stay signed in on this device.') }}</flux:text>
            </div>

            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />

            <div class="flex gap-3 justify-end">
                <flux:modal.close>
                    <flux:button variant="outline">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Log out other devices') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
