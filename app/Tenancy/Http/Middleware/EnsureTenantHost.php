<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\Facades\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantHost
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Tenant::check(), 404);

        return $next($request);
    }
}
