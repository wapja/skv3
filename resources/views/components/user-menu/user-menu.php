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
use Livewire\Component;

new class extends Component
{
    use ResolvesCurrentUser;

    /** 'sidebar' (desktop) or 'header' (mobile). */
    #[Locked]
    public string $variant = 'sidebar';

    /** The page the menu was opened on, captured on the server (RL-7); never taken from the Referer. */
    #[Locked]
    public string $returnPath = '/';

    public function mount(string $variant = 'sidebar'): void
    {
        $this->variant = $variant === 'header' ? 'header' : 'sidebar';
        $this->returnPath = '/'.ltrim(request()->path(), '/');
    }

    /**
     * Switch the language and reload the page so every string follows (§4.11).
     */
    public function setLocale(string $locale, UpdateLocale $updateLocale): void
    {
        $choice = Locale::tryFrom($locale)
            ?? throw ValidationException::withMessages(['locale' => __('Choose a supported language.')]);

        $updateLocale->handle($this->currentUser(), $choice);

        $this->redirect($this->returnPath);
    }

    /**
     * Store the appearance the switch just applied (DEC-12).
     */
    public function saveAppearance(string $appearance, UpdateAppearance $updateAppearance): void
    {
        $choice = Appearance::tryFrom($appearance)
            ?? throw ValidationException::withMessages(['appearance' => __('Choose light, dark or system.')]);

        $updateAppearance->handle($this->currentUser(), $choice);
    }

    /** @return array{user: User, locales: list<Locale>} */
    public function with(): array
    {
        return ['user' => $this->currentUser(), 'locales' => Locale::cases()];
    }
};
