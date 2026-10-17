<?php

declare(strict_types=1);

use App\Actions\Settings\RequestEmailChange;
use App\Actions\Settings\UpdateLocale;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Locale;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Auth\Impersonation;
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
        $this->locale = Impersonation::localeFor($user)->value;
    }

    /**
     * Save the name and the language; a new email address only applies after it is confirmed through the
     * mailed link (E1). A new language reloads the page, so it renders in that language (§4.10); the result
     * is then flashed in that language for the reloaded page instead of a toast the reload would drop (G3-06).
     * Sending the confirmation link is limited per user (CD-3): the slot is reserved before anything is saved,
     * so a refusal saves nothing, and given back when any part of the save throws (UserActionLimit::around()).
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
        $changesEmail = $email !== $user->email;
        $locale = Locale::from((string) $validated['locale']);
        $localeChanged = $locale !== Impersonation::localeFor($user);

        $save = function () use ($user, $validated, $email, $changesEmail, $locale, $localeChanged, $updateLocale, $requestEmailChange): void {
            $user->fill(['name' => $validated['name']])->save();

            if ($localeChanged) {
                $updateLocale->handle($user, $locale);
            }

            if ($changesEmail) {
                $requestEmailChange->handle($user, $email);
            }
        };

        if ($changesEmail) {
            UserActionLimit::emailChange()->around($user, 'email', $save);

            $this->email = $user->email;
            $status = __('We sent a confirmation link to :email.', ['email' => $email], $locale->value);
        } else {
            $save();

            $status = __('Profile updated.', [], $locale->value);
        }

        if (! $localeChanged) {
            Flux::toast(variant: 'success', text: $status);

            return;
        }

        // A toast dispatched now is lost on the full reload (G3-06): the status is flashed instead, in the new
        // language, and the base layout's flash→toast bridge shows it on the reloaded page.
        session()->flash('status', $status);
        $this->redirect(route('profile.edit'), navigate: false);
    }
};
