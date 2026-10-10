<?php

declare(strict_types=1);

use App\Concerns\ConfirmsPasswords;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component
{
    use ConfirmsPasswords;

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

        $generateNewRecoveryCodes($this->user());

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
        $user = $this->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }

    /**
     * Get the authenticated user.
     */
    private function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }
};
