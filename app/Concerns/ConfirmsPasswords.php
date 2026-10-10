<?php

declare(strict_types=1);

namespace App\Concerns;

use Livewire\Component;

/**
 * Password confirmation for Livewire actions (§4.2). The `password.confirm` route middleware guards the
 * page request only: Livewire updates never pass through it, and a snapshot taken from any account can
 * call an action with another session. Every action that changes or reveals a second factor checks the
 * confirmation again.
 *
 * @phpstan-require-extends Component
 */
trait ConfirmsPasswords
{
    /**
     * Send the user to the password confirmation screen, and back here afterwards, when their last
     * confirmation is older than `auth.password_timeout`. True means the action must stop.
     */
    protected function passwordConfirmationRequired(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt <= (int) config('auth.password_timeout')) {
            return false;
        }

        redirect()->setIntendedUrl(url()->previous());
        $this->redirectRoute('password.confirm');

        return true;
    }
}
