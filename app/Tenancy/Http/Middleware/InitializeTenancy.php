<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Models\Organisation;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** First middleware of the web group (also for Livewire updates): binds or forgets the tenant. */
final readonly class InitializeTenancy
{
    public function __construct(private TenantResolver $resolver) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class);
        $organisation = $this->resolver->resolve($request);

        if ($organisation instanceof Organisation) {
            $tenant->set($organisation);
        } else {
            $tenant->forget();
        }

        return $next($request);
    }
}
