@php($accounts = app(\App\Setup\QuickLogin::class)->accounts())

@if ($accounts !== [])
    <div data-test="quick-login" class="space-y-2 rounded-lg border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
        <flux:heading size="sm">{{ __('Quick login (local only)') }}</flux:heading>
        @foreach ($accounts as $account)
            <form method="POST" action="{{ route('quick-login') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $account['email'] }}" />
                <flux:button type="submit" size="sm" variant="ghost" class="w-full justify-start" data-test="quick-login-{{ str_replace(['@', '.'], '-', $account['email']) }}">
                    {{ $account['email'] }}
                    <span class="text-zinc-500">· {{ \App\Authorization\Role::tryFrom($account['role'])?->label() ?? $account['role'] }}</span>
                </flux:button>
            </form>
        @endforeach
    </div>
@endif
