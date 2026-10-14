{{-- §5.3.4, RL-6: the summary, read from the marker. Manual reasons can hold process output: everything is escaped. --}}
<x-layouts::setup>
    <div data-test="setup-summary" class="space-y-8">
        <flux:heading size="xl" level="1">{{ __('Setup is done.') }}</flux:heading>

        @if (($summary['urls'] ?? []) !== [])
            <ul class="space-y-1">
                @foreach ($summary['urls'] as $url)
                    <li><flux:link :href="$url">{{ $url }}</flux:link></li>
                @endforeach
            </ul>
        @endif

        @if (($summary['logins'] ?? []) !== [])
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Email') }}</flux:table.column>
                    <flux:table.column>{{ __('Password') }}</flux:table.column>
                    <flux:table.column>{{ __('Address') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($summary['logins'] as $login)
                        <flux:table.row>
                            <flux:table.cell>{{ $login['email'] }}</flux:table.cell>
                            <flux:table.cell>{{ $login['password'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $login['url'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        @if (($summary['manual'] ?? []) !== [])
            <div class="space-y-3">
                @foreach ($summary['manual'] as $manual)
                    <div data-test="setup-summary-manual-{{ $manual['step'] }}" class="space-y-1" x-data>
                        <div class="flex items-center gap-2">
                            <code class="rounded bg-zinc-100 px-2 py-1 text-sm dark:bg-zinc-800">{{ $manual['command'] }}</code>
                            <flux:button size="xs" x-on:click="navigator.clipboard?.writeText(@js($manual['command'])).catch(() => {})">{{ __('Copy') }}</flux:button>
                        </div>
                        <flux:text size="sm">{{ $manual['reason'] }}</flux:text>
                    </div>
                @endforeach
            </div>
        @endif

        @if (($summary['notes'] ?? []) !== [])
            <ul class="list-disc space-y-1 ps-5">
                @foreach ($summary['notes'] as $note)
                    <li><flux:text>{{ $note }}</flux:text></li>
                @endforeach
            </ul>
        @endif

        @if (($summary['next'] ?? []) !== [])
            <div data-test="setup-next-steps" class="space-y-2">
                <flux:heading size="lg" level="2">{{ __('Next steps') }}</flux:heading>
                <ol class="list-decimal space-y-1 ps-5">
                    @foreach ($summary['next'] as $step)
                        <li><flux:text>{{ $step }}</flux:text></li>
                    @endforeach
                </ol>
            </div>
        @endif

        <flux:button variant="primary" :href="$loginUrl" data-test="setup-login">
            {{ $superAdminEmail !== null ? __('Log in as :email', ['email' => $superAdminEmail]) : __('Go to the login page') }}
        </flux:button>
    </div>
</x-layouts::setup>
