<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Models\Organisation;
use App\Setup\SetupMode;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second middleware of the web group, after EnterSetupMode (also for Livewire updates): binds or forgets the tenant.
 * In setup mode it stands aside and forgets it.
 */
final readonly class InitializeTenancy
{
    public function __construct(private TenantResolver $resolver) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        // §5.3.4: in setup mode no organisation exists yet, and the apex is still being chosen.
        if ($request->attributes->get(SetupMode::ATTRIBUTE) === true) {
            app(CurrentTenant::class)->forget();

            return $next($request);
        }

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
