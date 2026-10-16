<?php

declare(strict_types=1);

use App\Actions\Settings\RequestEmailChange;
use App\Actions\Settings\UpdateLocale;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Locale;
use App\Support\RateLimits\UserActionLimit;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules;
    use ResolvesCurrentUser;

    public string $name = '';

    public string $email = '';

    public string $locale = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = $this->currentUser();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->locale = $user->locale->value;
    }

    /**
     * Save the name and the language; a new email address only applies after it is confirmed through the
     * mailed link (E1). A new language reloads the page, so it renders in that language (§4.10). Sending the
     * confirmation link is limited per user (CD-3): the slot is reserved before anything is saved, so a refusal
     * saves nothing, and given back when the request throws.
     */
    public function updateProfileInformation(RequestEmailChange $requestEmailChange, UpdateLocale $updateLocale): void
    {
        $user = $this->currentUser();

        $this->email = Str::lower($this->email);

        $validated = $this->validate([
            ...$this->profileRules($user->id, $user->organisation_id),
            'locale' => ['required', Rule::enum(Locale::class)],
        ]);

        $email = (string) $validated['email'];
        $emailChangeLimit = UserActionLimit::emailChange();

        if ($email !== $user->email) {
            $emailChangeLimit->reserve($user, 'email');
        }

        $user->fill(['name' => $validated['name']])->save();

        $locale = Locale::from((string) $validated['locale']);
        $localeChanged = $locale !== $user->locale;

        if ($localeChanged) {
            $updateLocale->handle($user, $locale);
        }

        if ($email !== $user->email) {
            try {
                $requestEmailChange->handle($user, $email);
            } catch (Throwable $exception) {
                $emailChangeLimit->release($user);

                throw $exception;
            }

            $this->email = $user->email;

            Flux::toast(variant: 'success', text: __('We sent a confirmation link to :email.', ['email' => $email]));
        } else {
            Flux::toast(variant: 'success', text: __('Profile updated.'));
        }

        if ($localeChanged) {
            $this->redirect(route('profile.edit'), navigate: false);
        }
    }
};
