<form method="POST" action="{{ route('locale.update') }}" class="flex justify-center gap-2" data-test="language-toggle">
    @csrf
    @foreach (\App\Enums\Locale::cases() as $locale)
        <flux:button
            type="submit"
            name="locale"
            value="{{ $locale->value }}"
            size="xs"
            :variant="app()->getLocale() === $locale->value ? 'filled' : 'ghost'"
        >
            {{ $locale->nativeName() }}
        </flux:button>
    @endforeach
</form>
