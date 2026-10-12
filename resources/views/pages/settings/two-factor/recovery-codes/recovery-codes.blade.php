<div
    class="py-6 space-y-6 border shadow-sm rounded-xl border-zinc-200 dark:border-white/10"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div class="px-6 space-y-2">
        <div class="flex items-center gap-2">
            <flux:icon.lock-closed variant="outline" class="size-4"/>
            <flux:heading size="lg" level="3">{{ __('2FA recovery codes') }}</flux:heading>
        </div>
        <flux:text variant="subtle">
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </flux:text>
    </div>

    <div class="px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:button
                x-show="!showRecoveryCodes"
                icon="eye"
                icon:variant="outline"
                variant="primary"
                @click="showRecoveryCodes = true;"
                aria-expanded="false"
                aria-controls="recovery-codes-section"
            >
                {{ __('View recovery codes') }}
            </flux:button>

            <flux:button
                x-show="showRecoveryCodes"
                icon="eye-slash"
                icon:variant="outline"
                variant="primary"
                @click="showRecoveryCodes = false"
                aria-expanded="true"
                aria-controls="recovery-codes-section"
            >
                {{ __('Hide recovery codes') }}
            </flux:button>

            @if (filled($recoveryCodes))
                <div x-show="showRecoveryCodes" class="flex flex-wrap gap-2">
                    <flux:button
                        icon="clipboard"
                        variant="filled"
                        x-data="{ copied: false, failed: false }"
                        x-on:click="navigator.clipboard.writeText(@js(implode(PHP_EOL, $recoveryCodes))).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => { failed = true; setTimeout(() => failed = false, 4000) })"
                    >
                        <span x-show="! copied && ! failed">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        <span x-show="failed" x-cloak>{{ __('Copying failed; select the codes and copy them yourself.') }}</span>
                    </flux:button>

                    <flux:button icon="arrow-down-tray" variant="filled" wire:click="downloadRecoveryCodes">
                        {{ __('Download') }}
                    </flux:button>

                    <flux:modal.trigger name="confirm-regenerate-recovery-codes">
                        <flux:button icon="arrow-path" variant="filled">{{ __('Regenerate codes') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            id="recovery-codes-section"
            class="relative overflow-hidden"
            x-bind:aria-hidden="!showRecoveryCodes"
        >
            <div class="mt-3 space-y-3">
                @error('recoveryCodes')
                    <flux:callout variant="danger" icon="x-circle" heading="{{$message}}"/>
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        class="grid gap-1 p-4 font-mono text-sm rounded-lg bg-zinc-100 dark:bg-white/5"
                        role="list"
                        aria-label="{{ __('Recovery codes') }}"
                    >
                        @foreach($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="select-text"
                                wire:loading.class="opacity-50 animate-pulse"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <flux:text variant="subtle" class="text-xs">
                        {{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.') }}
                    </flux:text>
                @endif
            </div>
        </div>
    </div>

    <flux:modal name="confirm-regenerate-recovery-codes" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Regenerate recovery codes?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Your current recovery codes stop working. Store the new codes before you leave this page.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="regenerateRecoveryCodes">{{ __('Regenerate codes') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
