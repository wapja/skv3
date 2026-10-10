<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Fortify's two-factor challenge (§4.1, §4.2): the challenged user is loaded without the tenant scope
 * and must still pass the host rule, so a user who was disabled or moved between the password step
 * and the code step is refused.
 */
final class UnscopedTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function hasChallengedUser(): bool
    {
        return $this->allowedChallengedUser() instanceof User;
    }

    public function challengedUser(): mixed
    {
        return $this->allowedChallengedUser()
            ?? throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse($this));
    }

    /** The failure response is built only in challengedUser(): it flashes an error (HTML) or throws (JSON). */
    private function allowedChallengedUser(): ?User
    {
        $userId = $this->session()->get('login.id');

        if (! is_int($userId) && ! is_string($userId)) {
            return null;
        }

        $user = app(CurrentTenant::class)->bypass(fn (): ?User => User::query()->find($userId));

        return $user instanceof User && app(HostLoginRule::class)->allows($user) ? $user : null;
    }
}
