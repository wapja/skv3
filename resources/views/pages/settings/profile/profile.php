<?php

declare(strict_types=1);

use App\Actions\Settings\RequestEmailChange;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesCurrentUser;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules;
    use ResolvesCurrentUser;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = $this->currentUser();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    /**
     * Save the name; a new email address only applies after it is confirmed through the mailed link (E1).
     */
    public function updateProfileInformation(RequestEmailChange $requestEmailChange): void
    {
        $user = $this->currentUser();

        $this->email = Str::lower($this->email);

        $validated = $this->validate($this->profileRules($user->id, $user->organisation_id));

        $user->fill(['name' => $validated['name']])->save();

        $email = (string) $validated['email'];

        if ($email !== $user->email) {
            $requestEmailChange->handle($user, $email);
            $this->email = $user->email;

            Flux::toast(variant: 'success', text: __('We sent a confirmation link to :email.', ['email' => $email]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }
};
