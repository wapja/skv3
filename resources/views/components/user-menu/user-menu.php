<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateAppearance;
use App\Actions\Settings\UpdateLocale;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Appearance;
use App\Enums\Locale;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component
{
    use ResolvesCurrentUser;

    /** 'sidebar' (desktop) or 'header' (mobile). */
    #[Locked]
    public string $variant = 'sidebar';

    public function mount(string $variant = 'sidebar'): void
    {
        $this->variant = $variant === 'header' ? 'header' : 'sidebar';
    }

    /**
     * Switch the language, then let the browser reload the page it shows, so every string follows (§4.11). The
     * browser's own URL keeps the query string and the filters changed since the page loaded (G3-07); the server
     * names no target, so nothing a client sends can redirect elsewhere (RL-7).
     */
    #[Renderless]
    public function setLocale(string $locale, UpdateLocale $updateLocale): void
    {
        $choice = Locale::tryFrom($locale)
            ?? throw ValidationException::withMessages(['locale' => __('Choose a supported language.')]);

        $updateLocale->handle($this->currentUser(), $choice);

        $this->js('window.location.reload()');
    }

    /**
     * Store the appearance a switch just applied (DEC-12). The only save point: the sidebar menu, mounted on
     * every app page, watches $flux.appearance; the header menu and the settings page only set it.
     */
    #[Renderless]
    public function saveAppearance(string $appearance, UpdateAppearance $updateAppearance): void
    {
        $choice = Appearance::tryFrom($appearance)
            ?? throw ValidationException::withMessages(['appearance' => __('Choose light, dark or system.')]);

        $updateAppearance->handle($this->currentUser(), $choice);
    }

    /** §6.6: the profile page saved or removed the photo; rendering again reads the user's new photo. */
    #[On('avatar-updated')]
    public function refreshAvatar(): void {}

    /** @return array{user: User, locales: list<Locale>} */
    public function with(): array
    {
        return ['user' => $this->currentUser(), 'locales' => Locale::cases()];
    }
};
