<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Actions\Users\StopImpersonation;
use App\Authorization\Permission;
use App\Authorization\UserAbilities;
use App\Models\User;
use App\Tenancy\Auth\HostLoginRule;
use App\Tenancy\Auth\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-validates a running impersonation on every web request (§4.7, RL-20), before Authenticate resolves
 * the signed-in user: the module is on; the impersonator still exists, is active, may use this host and has
 * the same password; the target still exists, is active, may use this host and has the same password as at
 * the swap (else AuthenticateSession would sign the browser out unlogged); and the matrix still allows the
 * pair. Otherwise the impersonation ends, logged with the reason (module_disabled, impersonator_unavailable,
 * impersonator_credentials_changed, target_unavailable, target_credentials_changed, no_longer_allowed), and
 * the impersonator is back in their own account, or signed out when they no longer qualify themselves
 * (W3, RK-B4, RK-I1, RK-I2, RK-I3).
 * Both users are read from the session through the unscoped provider; the guard is never asked.
 */
final readonly class EnforceImpersonation
{
    public function __construct(
        private HostLoginRule $rule,
        private UserAbilities $abilities,
        private StopImpersonation $stopImpersonation,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Impersonation::isActive()) {
            return $next($request);
        }

        $reason = $this->endReason();

        if ($reason === null) {
            return $next($request);
        }

        $impersonator = $this->stopImpersonation->handle($reason);

        if (! $impersonator instanceof User) {
            return to_route('login')->with('status', __('The impersonation has ended. Sign in again.'));
        }

        return to_route($impersonator->can(Permission::UsersView) ? 'users.index' : 'dashboard')
            ->with('status', __('The impersonation has ended. You are back in your own account.'));
    }

    private function endReason(): ?string
    {
        if (! Impersonation::enabled()) {
            return 'module_disabled';
        }

        $impersonator = Impersonation::impersonator();

        if (! $impersonator instanceof User || ! $this->rule->allows($impersonator)) {
            return 'impersonator_unavailable';
        }

        if (! Impersonation::impersonatorPasswordUnchanged($impersonator)) {
            return 'impersonator_credentials_changed';
        }

        $target = Impersonation::target();

        if (! $target instanceof User || ! $this->rule->allows($target)) {
            return 'target_unavailable';
        }

        if (! Impersonation::targetPasswordUnchanged($target)) {
            return 'target_credentials_changed';
        }

        return $this->abilities->mayImpersonate($impersonator, $target) ? null : 'no_longer_allowed';
    }
}
