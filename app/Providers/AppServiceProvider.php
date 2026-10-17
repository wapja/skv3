<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Audit\ConsoleOrigin;
use App\Authorization\SuperAdminGate;
use App\Console\Support\StreamTerminal;
use App\Console\Support\Terminal;
use App\Policies\RolePolicy;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Detector;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Http\EnsureSetupAccess;
use App\Setup\SetupContext;
use App\Setup\SetupMode;
use App\Setup\SetupToken;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\BootstrapRunner;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\DetachedStepLauncher;
use App\Setup\Wizard\StepLauncher;
use App\Support\Backups\ConfigurePgDump;
use App\Support\Backups\PrivateBackupTemp;
use App\Support\Backups\RefuseUnencryptedBackups;
use App\Support\FluxProBranches;
use App\Support\Locales;
use App\Support\Skv;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\DevCommands;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        AliasLoader::getInstance()->alias('Skv', Skv::class);

        // Child processes of setup never inherit this project's .env values (RL-12); tests bind a sandbox toolchain.
        $this->app->bind(Toolchain::class, fn (): Toolchain => new Toolchain(basePath: base_path()));
        $this->app->bind(Bootstrap::class, fn (Application $app): Bootstrap => new Bootstrap($app->basePath(), $app->make(Toolchain::class)));
        $this->app->bind(BootstrapRunner::class, fn (Application $app): BootstrapRunner => $app->make(Bootstrap::class));
        // skv:doctor examines the running application; tests bind a sandbox context instead (§5.4).
        $this->app->bind(DoctorContext::class, fn (Application $app): DoctorContext => DoctorContext::forApplication($app->make(Toolchain::class), $app->make(Detector::class)));
        // skv:install sets up this application; tests bind a sandbox context instead (RL-2).
        $this->app->bind(SetupContext::class, fn (Application $app): SetupContext => SetupContext::forApplication(
            Answers::defaults(Detection::none(), basename($app->basePath())), Detection::none(),
        ));
        // Setup mode and its token belong to this application; tests bind a sandbox instead (RL-1).
        $this->app->bind(SetupMode::class, fn (): SetupMode => new SetupMode(base_path()));
        $this->app->bind(SetupToken::class, fn (): SetupToken => new SetupToken(base_path()));
        // The wizard's long steps and the Herd step run detached (RL-4); tests bind an inline launcher.
        $this->app->bind(StepLauncher::class, DetachedStepLauncher::class);
        // --password-stdin refuses a terminal (L-54); tests bind one that always is.
        $this->app->bind(Terminal::class, StreamTerminal::class);

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
        $this->configureSetupWizard();
        $this->configureOperations();
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

        // CD-10: the strict rule everywhere but local development and the test suite, so a staging server (where
        // skv:install creates a super admin, M7b CD-3) gets the production rule.
        Password::defaults(fn (): ?Password => ! app()->environment(['local', 'testing'])
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
     * §5.3.4 / RL-2: the setup gate is re-applied on every Livewire update of the wizard, which arrives on the
     * shared update route without the wizard's route middleware.
     */
    protected function configureSetupWizard(): void
    {
        Livewire::addPersistentMiddleware([EnsureSetupAccess::class]);
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

    /** §4.9: the console side of the ops layer. */
    protected function configureOperations(): void
    {
        // CD-10: backup:run never writes an unencrypted archive, also when it is started by hand.
        Event::listen(CommandStarting::class, RefuseUnencryptedBackups::class);
        Event::listen(CommandStarting::class, ConfigurePgDump::class);
        // CD-10, L-07: the plaintext dump's working directory is private to this account.
        Event::listen(CommandStarting::class, PrivateBackupTemp::class);
        // CD-7, RL-9: audit entries written during a command are tagged with it.
        Event::listen(CommandStarting::class, fn (CommandStarting $event) => ConsoleOrigin::enter($event->command));
        Event::listen(CommandFinished::class, fn (CommandFinished $event) => ConsoleOrigin::leave($event->command));
    }
}
