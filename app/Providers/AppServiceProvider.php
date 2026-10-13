<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\SuperAdminGate;
use App\Policies\RolePolicy;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Detector;
use App\Setup\Doctor\DoctorContext;
use App\Setup\SetupContext;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\Toolchain;
use App\Support\FluxProBranches;
use App\Support\Locales;
use App\Support\Skv;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\DevCommands;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role as RoleModel;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        AliasLoader::getInstance()->alias('Skv', Skv::class);

        $this->app->bind(Bootstrap::class, fn (Application $app): Bootstrap => new Bootstrap($app->basePath(), $app->make(Toolchain::class)));
        // skv:doctor examines the running application; tests bind a sandbox context instead (§5.4).
        $this->app->bind(DoctorContext::class, fn (Application $app): DoctorContext => DoctorContext::forApplication($app->make(Toolchain::class), $app->make(Detector::class)));
        // skv:install sets up this application; tests bind a sandbox context instead (RL-2).
        $this->app->bind(SetupContext::class, fn (Application $app): SetupContext => SetupContext::forApplication(
            Answers::defaults(Detection::none(), basename($app->basePath())), Detection::none(),
        ));

        $this->configureModules();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
        $this->configureAuthorization();
        $this->configureErrorPages();
        $this->configureFluxProBranches();
    }

    /**
     * §6.8 / RL-3: with the passkeys module off, Fortify registers no passkey routes. This runs in register(),
     * before Fortify's boot() reads config('fortify.features') to register its routes.
     */
    protected function configureModules(): void
    {
        if (Skv::passkeysEnabled()) {
            return;
        }

        /** @var list<string> $features */
        $features = config('fortify.features', []);

        config(['fortify.features' => array_values(array_filter(
            $features,
            fn (string $feature): bool => $feature !== Features::passkeys(),
        ))]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Values echoed into Markdown mails (organisation names, addresses) never become links or HTML.
        Markdown::withSecuredEncoding();

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure `php artisan dev`: Herd already serves the site, and the scheduler must run locally.
     */
    protected function configureDevCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        DevCommands::artisan('schedule:work', 'schedule');

        if (is_dir((getenv('HOME') ?: '').'/Library/Application Support/Herd')) {
            DevCommands::except('server');
        }
    }

    /**
     * Super admins pass every gate check except the always-checked abilities (§4.6). Spatie's
     * role model lives outside App\Models, so policy discovery does not find RolePolicy.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(SuperAdminGate::before(...));

        Gate::policy(RoleModel::class, RolePolicy::class);
    }

    /**
     * Error pages speak the visitor's language (§6.5). An unknown route never runs the web middleware, so the
     * locale is resolved here: the signed-in user (only when the guard already resolved them, RL-20), the guest's
     * choice, the browser.
     */
    protected function configureErrorPages(): void
    {
        View::composer(['errors::*', 'errors.*'], function (): void {
            Locales::apply(Locales::forErrorPage(request()));
        });
    }

    /**
     * §6.7: without Flux Pro, the Pro branches of the views are removed before Blade compiles them, because Blade
     * resolves component tags at compile time. This follows the installed package, not Skv::useFluxPro(): compiled
     * views outlive a single test or request. Registered in boot(), so it runs before Blaze's own hook (booted()).
     */
    protected function configureFluxProBranches(): void
    {
        if (InstalledVersions::isInstalled('livewire/flux-pro')) {
            return;
        }

        Blade::prepareStringsForCompilationUsing(FluxProBranches::strip(...));
    }
}
