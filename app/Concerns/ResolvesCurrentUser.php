<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The signed-in user of a Livewire component (DEC-25 (2)). Components keep no User property: Livewire
 * rehydrates public models without the tenant scope (§6.2), so every request reads the user again.
 *
 * @phpstan-require-extends Component
 */
trait ResolvesCurrentUser
{
    /** @throws AuthenticationException */
    protected function currentUser(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }
}
