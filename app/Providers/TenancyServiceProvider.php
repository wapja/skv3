<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\Auth\UnscopedUserProvider;
use App\Tenancy\CentralContext;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Http\Middleware\EnsureCentralHost;
use App\Tenancy\Http\Middleware\EnsureTenantHost;
use App\Tenancy\Listeners\SetPermissionsTeam;
use App\Tenancy\Resolvers\SubdomainResolver;
use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
        $this->app->singleton(CentralContext::class);
        $this->app->bind(TenantResolver::class, SubdomainResolver::class);
    }

    public function boot(): void
    {
        Auth::provider('unscoped-eloquent', fn (Application $app, array $config): UnscopedUserProvider => new UnscopedUserProvider(
            $app->make('hash'),
            (string) $config['model'],
        ));

        Event::listen(TenantResolved::class, SetPermissionsTeam::class);
        Event::listen(TenantForgotten::class, SetPermissionsTeam::class);
        Event::listen(CommandStarting::class, function (): void {
            $this->app->make(CentralContext::class)->enter();
        });
        Event::listen(CommandFinished::class, function (): void {
            $this->app->make(CentralContext::class)->leave();
        });

        // §6.2: host groups attach middleware instead of Route::domain(), so route names,
        // route() calls and mount() signatures never carry a {tenant} parameter.
        Route::macro('tenant', function (Closure $routes): void {
            Route::middleware(EnsureTenantHost::class)->group($routes);
        });
        Route::macro('central', function (Closure $routes): void {
            Route::middleware(EnsureCentralHost::class)->group($routes);
        });

        // Re-applied on the Livewire update request for components mounted on these routes (FQCN required).
        Livewire::addPersistentMiddleware([EnsureTenantHost::class, EnsureCentralHost::class]);
    }
}
