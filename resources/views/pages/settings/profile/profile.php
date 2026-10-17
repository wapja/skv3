<?php

declare(strict_types=1);

use App\Actions\Settings\RequestEmailChange;
use App\Actions\Settings\UpdateLocale;
use App\Actions\Users\RemoveAvatar;
use App\Actions\Users\UpdateAvatar;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Locale;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Auth\Impersonation;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules;
    use ResolvesCurrentUser;
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public string $locale = '';

    /** The chosen profile photo, uploaded to Livewire's temporary disk until saveAvatar() stores it (§6.6). */
    public ?TemporaryUploadedFile $avatar = null;

    /** Whether the photo section renders: never during an impersonation; the actions check again on every request. */
    #[Locked]
    public bool $canManageAvatar = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = $this->currentUser();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->locale = Impersonation::localeFor($user)->value;
        $this->canManageAvatar = ! Impersonation::isActive();
    }

    /** The stored photo's URL for the preview, or null for the initials. */
    #[Computed]
    public function avatarUrl(): ?string
    {
        return $this->currentUser()->avatarUrl();
    }

    /** The initials the preview shows without a photo. */
    #[Computed]
    public function initials(): string
    {
        return $this->currentUser()->initials();
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

    /**
     * Store the chosen photo as the user's own (§6.6): validated here, re-encoded and stored by UpdateAvatar, whose
     * "could not be processed" error lands on the same field. The user menu re-renders on avatar-updated.
     */
    public function saveAvatar(UpdateAvatar $updateAvatar): void
    {
        $this->ensureAvatarManageable();

        $this->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ]);

        $file = $this->avatar ?? throw new LogicException('The validated photo is missing.');

        $updateAvatar->handle($this->currentUser(), $file);

        $this->reset('avatar');
        unset($this->avatarUrl);
        $this->dispatch('avatar-updated');
        Flux::toast(variant: 'success', text: __('Profile photo saved.'));
    }

    /** Remove the user's own photo; the initials show again (§6.6). */
    public function removeAvatar(RemoveAvatar $removeAvatar): void
    {
        $this->ensureAvatarManageable();

        $removeAvatar->handle($this->currentUser());

        $this->reset('avatar');
        unset($this->avatarUrl);
        $this->dispatch('avatar-updated');
        Flux::toast(variant: 'success', text: __('Profile photo removed.'));
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['avatar' => __('profile photo')];
    }

    /**
     * §6.6, CD-7: the impersonated user's photo is theirs, so the photo actions answer 403 during an impersonation,
     * read on every request rather than from the locked snapshot.
     */
    private function ensureAvatarManageable(): void
    {
        abort_if(Impersonation::isActive(), 403);
    }
};
