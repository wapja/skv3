@if (\App\Tenancy\Auth\Impersonation::enabled() && \App\Tenancy\Auth\Impersonation::isActive())
    <div
        class="sticky top-0 z-30 -mx-6 -mt-6 mb-6 flex flex-wrap items-center justify-center gap-3 bg-amber-100 px-4 py-2 text-sm text-amber-900 lg:-mx-8 lg:-mt-8 dark:bg-amber-900 dark:text-amber-100"
        role="status"
        data-test="impersonation-banner"
    >
        <span>{{ __('Logged in as :name', ['name' => auth()->user()?->name]) }}</span>

        <form method="POST" action="{{ route('impersonation.stop') }}">
            @csrf
            @method('DELETE')

            <flux:button type="submit" size="sm" variant="filled" icon="arrow-uturn-left" data-test="stop-impersonating">
                {{ __('Back to your own account') }}
            </flux:button>
        </form>
    </div>
@endif
