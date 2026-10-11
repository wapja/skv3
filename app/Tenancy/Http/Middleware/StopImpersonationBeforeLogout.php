<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Actions\Users\StopImpersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing out while impersonating first ends the impersonation (logged, ended_because logout), so the
 * logout that follows signs the real user out and never cycles the impersonated user's remember token
 * (§4.7, RL-20).
 */
final readonly class StopImpersonationBeforeLogout
{
    public function __construct(private StopImpersonation $stopImpersonation) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->stopImpersonation->handle('logout');

        return $next($request);
    }
}
