<?php

declare(strict_types=1);

namespace App\Livewire\Actions;

use App\Actions\Users\StopImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Features\SupportRedirects\Redirector;

class Logout
{
    public function __construct(private readonly StopImpersonation $stopImpersonation) {}

    /**
     * Log the current user out of the application. A running impersonation ends first (logged), so the
     * impersonated user's remember token is left alone (RK-M5).
     */
    public function __invoke(): Redirector|RedirectResponse
    {
        $this->stopImpersonation->handle('logout');

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        return redirect('/');
    }
}
