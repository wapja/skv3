<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\SuperAdminGate;
use App\Policies\RolePolicy;
use App\Support\Skv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\DevCommands;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
}
