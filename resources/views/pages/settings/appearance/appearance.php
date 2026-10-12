<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateAppearance;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Appearance;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component
{
    use ResolvesCurrentUser;

    /**
     * Store the appearance the switch just applied (DEC-12).
     */
    public function saveAppearance(string $appearance, UpdateAppearance $updateAppearance): void
    {
        $choice = Appearance::tryFrom($appearance)
            ?? throw ValidationException::withMessages(['appearance' => __('Choose light, dark or system.')]);

        $updateAppearance->handle($this->currentUser(), $choice);
    }
};
