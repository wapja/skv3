<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out inactive users and users on a host they do not belong to, with a reason (§4.1).
 * M4 adds the impersonation case (end the impersonation instead of logging out).
 */
final class VerifyTenantMembership
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ($user->isActive() && $user->belongsToCurrentHost())) {
            return $next($request);
        }

        $reason = $user->isActive()
            ? __('Your account does not have access to this address. Sign in at your own organisation.')
            : __('Your account is not active. Contact your administrator.');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $reason);
    }
}
