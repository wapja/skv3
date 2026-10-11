<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Models\User;
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

        $reason = $user->isActive() && ! $user->trashed()
            ? __('Your account does not have access to this address. Sign in at your own organisation.')
            : __('Your account is not active. Contact your administrator.');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $reason);
    }
}
