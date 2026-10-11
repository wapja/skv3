<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Events\Login;

/** A real sign-in in this session (login form, passkey, handoff, accepted invitation) is never an impersonation. */
final class ClearImpersonationOnLogin
{
    /** The Login type registers this listener through event discovery; the impersonation swap lives on the web guard. */
    public function handle(Login $event): void
    {
        if ($event->guard === 'web') {
            Impersonation::clear();
        }
    }
}
