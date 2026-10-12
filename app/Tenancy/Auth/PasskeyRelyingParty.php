<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Tenancy\Hosts;
use Illuminate\Support\Str;

/**
 * The WebAuthn relying-party id (§4.2): always the apex, lower-cased, so one passkey works on the apex, the
 * admin host and every organisation's host (FU3-2). PASSKEYS_RELYING_PARTY_ID overrides it.
 */
final class PasskeyRelyingParty
{
    public static function id(): string
    {
        $override = config('fortify.passkeys.relying_party_id');

        return Str::lower(is_string($override) && $override !== '' ? $override : Hosts::apex());
    }
}
