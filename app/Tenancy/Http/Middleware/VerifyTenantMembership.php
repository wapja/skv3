<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use App\Tenancy\Auth\HostLoginRule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out a signed-in user the host login rule refuses — inactive, deleted, or on a host they do not
 * belong to — with a reason (§4.1, FU3-6). A running impersonation never reaches this point with a refused
 * user: EnforceImpersonation re-checked the pair before and ended the impersonation instead (RL-20).
 */
final readonly class VerifyTenantMembership
{
    public function __construct(private HostLoginRule $rule) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->rule->allows($user)) {
            return $next($request);
        }

        $active = $user->isActive() && ! $user->trashed();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // SetLocale has not run yet, and the login page answers a guest: word the reason in that guest's language (G3-01).
        $locale = Locales::forNextPage($request, null)->value;
        $reason = $active
            ? __('Your account does not have access to this address. Sign in at your own organisation.', [], $locale)
            : __('Your account is not active. Contact your administrator.', [], $locale);

        return redirect()->route('login')->with('status', $reason);
    }
}
