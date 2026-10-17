<?php

declare(strict_types=1);

use App\Concerns\ConfirmsPasswords;
use App\Concerns\ResolvesCurrentUser;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Encryption\DecryptException;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component
{
    use ConfirmsPasswords;
    use ResolvesCurrentUser;

    /**
     * @var list<string>
     */
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        if ($this->passwordConfirmationRequired()) {
            return;
        }

        $generateNewRecoveryCodes($this->currentUser());

        $this->loadRecoveryCodes();

        Flux::modal('confirm-regenerate-recovery-codes')->close();
    }

    /**
     * Download the recovery codes as a text file, one code per line (§4.2).
     */
    public function downloadRecoveryCodes(): ?StreamedResponse
    {
        if ($this->passwordConfirmationRequired()) {
            return null;
        }

        $user = $this->currentUser();

        // FU3-4: another tab may have regenerated the codes, or turned two-factor off, since this page loaded.
        // Fortify's recoveryCodes() decrypts the column and throws on null, so check first.
        if (! $user->hasEnabledTwoFactorAuthentication() || $user->two_factor_recovery_codes === null) {
            $this->recoveryCodes = [];
            $this->addError('recoveryCodes', __('There are no recovery codes to download.'));

            return null;
        }

        // The stored codes, not this page's snapshot; the page then shows the same codes it downloads.
        $stored = $this->storedRecoveryCodes($user);

        if ($stored === null) {
            $this->recoveryCodes = [];
            $this->addError('recoveryCodes', __('Failed to load recovery codes'));

            return null;
        }

        $this->recoveryCodes = $stored;
        $codes = implode(PHP_EOL, $this->recoveryCodes).PHP_EOL;

        return response()->streamDownload(function () use ($codes): void {
            echo $codes;
        }, 'recovery-codes.txt', ['Content-Type' => 'text/plain']);
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = $this->currentUser();

        if (! $user->hasEnabledTwoFactorAuthentication() || ! $user->two_factor_recovery_codes) {
            return;
        }

        $stored = $this->storedRecoveryCodes($user);

        if ($stored === null) {
            $this->addError('recoveryCodes', __('Failed to load recovery codes'));
        }

        $this->recoveryCodes = $stored ?? [];
    }

    /**
     * The stored codes, or null when they cannot be read (G2-07): encrypted with another APP_KEY that
     * APP_PREVIOUS_KEYS does not list, encrypted without being serialised first (unserialize() warns, which Laravel
     * turns into an ErrorException), or not a non-empty JSON list of strings. Decrypted like Fortify's
     * recoveryCodes().
     *
     * @return list<string>|null
     */
    private function storedRecoveryCodes(User $user): ?array
    {
        try {
            $decrypted = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_recovery_codes);
        } catch (DecryptException|ErrorException) {
            return null;
        }

        $codes = is_string($decrypted) ? json_decode($decrypted, true) : null;

        if (! is_array($codes) || $codes === [] || ! array_is_list($codes)) {
            return null;
        }

        $strings = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                return null;
            }

            $strings[] = $code;
        }

        return $strings;
    }
};
