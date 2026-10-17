<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Auth\SecondFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the second factor (§6.6, CD-4) on the authenticated route groups (routes/web.php, settings.php and
 * admin.php), not on the global web group. A user the rule applies to who has no second factor yet is sent to the
 * security page with an explanation on a GET, and refused (403) on any other method. It lets through: guests and
 * users the rule does not apply to; an impersonation session (the impersonator acts, and cannot set up the
 * target's factor); Livewire's update, upload and preview routes (the factor is set up through Livewire updates on
 * the security page); and the routes named in config('skv.second_factor.exempt_routes'), which set up a factor,
 * confirm a password, sign out, finish a handoff, end an impersonation or run the setup wizard.
 *
 * Livewire requests are recognised by their route, never by the X-Livewire header, which any client can send with
 * an ordinary page request. Livewire's own routes pass by name; the middleware is also Livewire persistent middleware
 * (TenancyServiceProvider), so an update passes only for a component of an exempt page.
 */
final class EnsureSecondFactor
{
    /** Livewire's own routes; the update route's name carries a prefix ("default-livewire.update"). */
    private const array LIVEWIRE_ROUTES = ['*livewire.update', 'livewire.upload-file', 'livewire.preview-file'];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->exempt($request) || ! SecondFactor::missingFor($user)) {
            return $next($request);
        }

        abort_unless(in_array($request->getMethod(), ['GET', 'HEAD'], true), 403);

        return redirect()->route('security.edit')->with('status', SecondFactor::explanation($user));
    }

    private function exempt(Request $request): bool
    {
        if (Impersonation::isActive() || $request->routeIs(...self::LIVEWIRE_ROUTES)) {
            return true;
        }

        /** @var list<string> $routes */
        $routes = config('skv.second_factor.exempt_routes', []);

        return $routes !== [] && $request->routeIs(...$routes);
    }
}
