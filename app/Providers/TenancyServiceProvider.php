<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Tenancy\Auth\HostLoginRule;
use App\Tenancy\Auth\UnscopedPasskey;
use App\Tenancy\Auth\UnscopedTwoFactorLoginRequest;
use App\Tenancy\Auth\UnscopedUserProvider;
use App\Tenancy\CentralContext;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Http\Middleware\EnsureCentralHost;
use App\Tenancy\Http\Middleware\EnsureTenantHost;
use App\Tenancy\Listeners\SetPasskeyOrigin;
use App\Tenancy\Listeners\SetPermissionsTeam;
use App\Tenancy\Queue\JobTenancy;
use App\Tenancy\Resolvers\SubdomainResolver;
use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
        $this->app->singleton(CentralContext::class);
        $this->app->singleton(JobTenancy::class);
        $this->app->bind(TenantResolver::class, SubdomainResolver::class);
        $this->app->bind(TwoFactorLoginRequest::class, UnscopedTwoFactorLoginRequest::class);
    }

    public function boot(): void
    {
        Auth::provider('unscoped-eloquent', fn (Application $app, array $config): UnscopedUserProvider => new UnscopedUserProvider(
            $app->make('hash'),
            (string) $config['model'],
        ));

        Passkeys::usePasskeyModel(UnscopedPasskey::class);
        // §4.2: passkey sign-in follows the same host rule as password sign-in (P2).
        Passkeys::authorizeLoginUsing(fn (Request $request, mixed $user): bool => $user instanceof User
            && app(HostLoginRule::class)->allows($user));

        Event::listen(TenantResolved::class, SetPermissionsTeam::class);
        Event::listen(TenantForgotten::class, SetPermissionsTeam::class);
        Event::listen(TenantResolved::class, SetPasskeyOrigin::class);
        Event::listen(TenantForgotten::class, SetPasskeyOrigin::class);
        Event::listen(CommandStarting::class, function (): void {
            $this->app->make(CentralContext::class)->enter();
        });
        Event::listen(CommandFinished::class, function (): void {
            $this->app->make(CentralContext::class)->leave();
        });

        Queue::createPayloadUsing(fn (): array => [
            'tenant_id' => $this->app->make(CurrentTenant::class)->current()?->id,
        ]);
        Event::listen(JobProcessing::class, [JobTenancy::class, 'begin']);
        Event::listen(JobProcessed::class, [JobTenancy::class, 'end']);
        Event::listen(JobFailed::class, [JobTenancy::class, 'end']);
        Event::listen(JobExceptionOccurred::class, [JobTenancy::class, 'end']);

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
