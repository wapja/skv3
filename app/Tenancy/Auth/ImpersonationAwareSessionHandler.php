<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * The database session handler with one change (§4.7, RL-28): while a session impersonates, its sessions.user_id
 * is the impersonator, not the impersonated user the guard reports. The row follows the real person, so cutting
 * the target's sessions (disable, delete, archive) leaves the impersonation session for EnforceImpersonation to
 * end with a logged stop, instead of an empty session that falls back to the impersonator's remember-me cookie
 * (RK-I1). Disabling the impersonator still cuts this session; Sessions::endFor() clears their remember token with it.
 */
final class ImpersonationAwareSessionHandler extends DatabaseSessionHandler
{
    protected function userId(): mixed
    {
        return Impersonation::impersonatorId() ?? parent::userId();
    }
}
