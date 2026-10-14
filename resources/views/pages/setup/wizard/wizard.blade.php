<div data-test="setup-wizard" class="space-y-8">
    {{-- Outside every @if: the Herd stage arrives through a Livewire morph, and Livewire never runs a <script> that a morph inserts. --}}
    <script>
        function setupHerdStage(statusUrl, finishUrl, secret) {
            return {
                async poll() {
                    const query = '?secret=' + encodeURIComponent(secret);
                    const started = Date.now();

                    while (Date.now() - started < 60000) {
                        try {
                            const response = await fetch(statusUrl + query, { redirect: 'manual', headers: { Accept: 'application/json' } });

                            // Herd now answers http with a 301 to https (DEC-23): continue on the https origin.
                            if (response.type === 'opaqueredirect') {
                                window.location.href = 'https://' + window.location.host + finishUrl + query;
                                return;
                            }

                            const state = response.ok ? (await response.json()).state : null;

                            if (state === 'done') {
                                window.location.href = finishUrl + query;
                                return;
                            }

                            // The Herd step exited without an answer: the finish page confirms with Herd or offers the command.
                            if (state === 'timeout') {
                                break;
                            }
                        } catch (error) {
                            // nginx is restarting; try again.
                        }

                        await new Promise((resolve) => setTimeout(resolve, 2000));
                    }

                    window.location.href = finishUrl + query + '&timeout=1';
                },
            };
        }
    </script>

    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Set up :name', ['name' => $appName !== '' ? $appName : config('app.name')]) }}</flux:heading>
        <flux:text>{{ __('Answer a few questions; setup does the rest. You can change everything later.') }}</flux:text>
    </div>

    @if ($stage === 'questions')
        <form wire:submit="start" data-test="setup-questions" class="space-y-6">
            @if ($hostProblem !== null)
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="$hostProblem" />
            @endif
            <flux:error name="host" />

            <flux:input wire:model="appName" :label="__('App name')" required />

            <flux:radio.group wire:model="database" :label="__('Database')">
                <flux:radio value="pgsql" label="PostgreSQL" data-test="setup-database-pgsql" />
                <flux:radio value="sqlite" label="SQLite" data-test="setup-database-sqlite" />
            </flux:radio.group>

            <flux:radio.group wire:model.live="startWith" :label="__('Start with')">
                <flux:radio value="demo" :label="__('Demo data')" data-test="setup-start-demo" />
                <flux:radio value="super_admin" :label="__('My own super admin')" data-test="setup-start-super_admin" />
            </flux:radio.group>

            @if ($startWith === 'super_admin')
                <flux:input wire:model="superAdminEmail" type="email" :label="__('Super admin email')" required />
                <flux:input wire:model="superAdminPassword" type="password" :label="__('Super admin password')" viewable />
            @endif

            <flux:checkbox.group wire:model="modules" :label="__('Modules')">
                <flux:checkbox value="passkeys" :label="__('Passkeys')" />
                <flux:checkbox value="backups" :label="__('Backups')" />
                <flux:checkbox value="impersonation" :label="__('Impersonation')" />
            </flux:checkbox.group>

            <flux:switch wire:model="https" :label="__('Use HTTPS')" />
            <flux:switch wire:model="playwright" :label="__('Install Playwright')" />
            <flux:switch wire:model="claude" :label="__('Set up Claude Code')" />

            <flux:button type="submit" variant="primary" :disabled="$hostProblem !== null" data-test="setup-start-button">{{ __('Start setup') }}</flux:button>
        </form>
    @elseif (in_array($stage, ['running', 'review'], true))
        {{-- keep-alive: Livewire drops 95% of polls in a background tab, and every step waits for one. --}}
        <div @if ($stage === 'running' && $failure === null) wire:poll.2s.keep-alive="advance" @endif class="space-y-3">
            @foreach ($this->steps as $step)
                <div data-test="setup-step-{{ $step['key'] }}" class="flex items-start justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="space-y-1">
                        <flux:heading size="sm">{{ $step['label'] }}</flux:heading>
                        @if ($step['message'] !== '')
                            <flux:text size="sm">{{ $step['message'] }}</flux:text>
                        @endif
                        @if ($step['status'] === 'manual')
                            <div data-test="setup-manual-{{ $step['key'] }}" class="flex items-center gap-2" x-data>
                                <code class="rounded bg-zinc-100 px-2 py-1 text-sm dark:bg-zinc-800">{{ $step['command'] }}</code>
                                <flux:button size="xs" x-on:click="navigator.clipboard?.writeText(@js($step['command'])).catch(() => {})">{{ __('Copy') }}</flux:button>
                                <flux:button size="xs" wire:click="recheck(@js($step['key']))">{{ __('Re-check') }}</flux:button>
                            </div>
                        @endif
                    </div>
                    <flux:badge size="sm" :color="match ($step['status']) { 'done' => 'green', 'skipped' => 'zinc', 'manual' => 'amber', default => 'sky' }">
                        {{ match ($step['status']) { 'done' => __('done'), 'skipped' => __('skipped'), 'manual' => __('manual'), default => __('waiting') } }}
                    </flux:badge>
                </div>
            @endforeach
        </div>

        @if ($stage === 'review')
            <div data-test="setup-review" class="space-y-3">
                <flux:text>{{ __('Run the commands above and re-check them, or finish setup now and run them later.') }}</flux:text>
                <flux:button variant="primary" wire:click="complete" data-test="setup-complete">{{ __('Finish setup') }}</flux:button>
            </div>
        @endif

        @if ($failure !== null)
            <flux:callout data-test="setup-failure" variant="danger" icon="x-circle" :heading="__('Setup stopped')" :text="$failure">
                <x-slot name="actions">
                    <flux:button wire:click="retry">{{ __('Try again') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @else
        <div data-test="setup-herd-stage" class="space-y-3"
             x-data="setupHerdStage(@js(route('setup.herd-status', absolute: false)), @js(route('setup.finish', absolute: false)), @js($herdSecret))"
             x-init="poll()">
            <flux:heading size="lg">{{ __('Finishing the Herd site…') }}</flux:heading>
            <flux:text>{{ __('Herd may restart; this page continues by itself.') }}</flux:text>
        </div>
    @endif
</div>
