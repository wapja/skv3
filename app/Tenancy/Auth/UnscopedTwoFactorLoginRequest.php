<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Tenancy\CurrentTenant;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/** Fortify's two-factor challenge looks the user up without the tenant scope (§4.1). */
final class UnscopedTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function hasChallengedUser()
    {
        return app(CurrentTenant::class)->bypass(fn (): bool => (bool) parent::hasChallengedUser());
    }

    public function challengedUser()
    {
        return app(CurrentTenant::class)->bypass(fn (): mixed => parent::challengedUser());
    }
}
