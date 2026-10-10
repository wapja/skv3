<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\Auth\UnscopedUserProvider;
use App\Tenancy\CentralContext;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Listeners\SetPermissionsTeam;
use App\Tenancy\Resolvers\SubdomainResolver;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
    }
}
