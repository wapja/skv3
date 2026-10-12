<?php

declare(strict_types=1);

use App\Actions\Invitations\InviteUser;
use App\Actions\Users\StartImpersonation;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Providers\FortifyServiceProvider;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\SetupContext;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Hosts;
use App\Tenancy\Listeners\SetPasskeyOrigin;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Pest\Support\Reflection;
use Pest\TestSuite;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Arch');

// §7.2: browser tests serve the app in-process; RefreshDatabase on in-memory SQLite works because the
// server shares the test's container and connection. The group keeps them out of composer check.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->beforeEach(function (): void {
        // RL-2: a running `npm run dev` (public/hot) must not leak dev-server URLs into a browser run.
        Vite::useHotFile(storage_path('framework/testing/no-vite-hot'));
    })
    ->in('Browser');

/*
| Tenancy testing API (§7.2). Each helper sets the URL origin, so route() and $this->get()
| target that host, and binds or forgets the tenant for code that runs without middleware
| (Livewire::test(), direct model calls).
*/

function onTenant(Organisation $organisation): Organisation
{
    URL::useOrigin(Hosts::origin(Hosts::tenant($organisation->slug)));
    app(CurrentTenant::class)->set($organisation);

    return $organisation;
}

function onApex(): void
{
    URL::useOrigin(Hosts::origin(Hosts::apex()));
    app(CurrentTenant::class)->forget();
}

function onAdminHost(): void
{
    URL::useOrigin(Hosts::origin(Hosts::admin()));
    app(CurrentTenant::class)->forget();
}

function asSuperAdmin(): User
{
    $user = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($user, true);
    actingAs($user);

    return $user;
}

function asOrgAdmin(Organisation $organisation): User
{
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');
    actingAs($user);
    onTenant($organisation);

    return $user;
}

function asMember(Organisation $organisation): User
{
    $user = User::factory()->for($organisation)->create();
    actingAs($user);
    onTenant($organisation);

    return $user;
}

/**
 * Base-relative paths of every .php file (Blade included) under the given directories, for
 * rules that Pest's arch() cannot express: static method calls, string literals, anonymous
 * migration classes and closure-style test files.
 *
 * @return list<string>
 */
function kitSourceFiles(string ...$directories): array
{
    return array_values(collect($directories)
        ->filter(fn (string $directory): bool => is_dir(base_path($directory)))
        ->flatMap(fn (string $directory): array => File::allFiles(base_path($directory)))
        ->map(fn (SplFileInfo $file): string => Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR))
        ->filter(fn (string $path): bool => str_ends_with($path, '.php'))
        ->all());
}

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function filesMatching(array $paths, string $pattern): array
{
    return array_values(array_filter(
        $paths,
        fn (string $path): bool => preg_match($pattern, (string) file_get_contents(base_path($path))) === 1,
    ));
}

/** The email-change confirmation link sent to an address (requires Notification::fake()). */
function confirmationLinkFor(string $address): string
{
    $link = null;

    Notification::assertSentOnDemand(
        ConfirmEmailChange::class,
        function (ConfirmEmailChange $notification, array $channels, AnonymousNotifiable $notifiable) use ($address, &$link): bool {
            if (($notifiable->routes['mail'] ?? null) !== $address) {
                return false;
            }

            $link = $notification->url;

            return true;
        },
    );

    return is_string($link) ? $link : throw new RuntimeException("No confirmation link was sent to [{$address}].");
}

/** @return list<string> */
function dutchTranslationKeys(): array
{
    /** @var array<string, string> $dutch */
    $dutch = json_decode((string) file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);

    return array_keys($dutch);
}

/**
 * Every audit entry with this event, oldest first, read across organisations and including archived
 * (soft-deleted) rows, whatever host the test is on.
 *
 * @return EloquentCollection<int, Activity>
 */
function recordedActivity(ActivityEvent $event): EloquentCollection
{
    return app(CurrentTenant::class)->bypass(fn (): EloquentCollection => Activity::query()
        ->withTrashed()
        ->where('event', $event->value)
        ->orderBy('id')
        ->get());
}

/**
 * The snapshot of $component as the page at $url renders it for the signed-in user (generic form of the
 * M3 security-page helper). The URL decides the host.
 *
 * @return array<string, mixed>
 */
function pageSnapshot(string $url, string $component): array
{
    preg_match_all('/wire:snapshot="([^"]*)"/', (string) get($url)->assertOk()->getContent(), $matches);

    foreach ($matches[1] as $encoded) {
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode(htmlspecialchars_decode($encoded, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);

        if (($snapshot['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new LogicException("The page [{$url}] renders no [{$component}] component.");
}

/**
 * Sends a Livewire update that calls $method on the snapshot's component, as a forged request would: with
 * client-chosen parameters and property updates, from the current origin or from $host.
 *
 * @param  array<string, mixed>  $snapshot
 * @param  list<mixed>  $params
 * @param  array<string, mixed>  $updates
 * @return TestResponse<Response>
 */
function callPageAction(array $snapshot, string $method, array $params = [], array $updates = [], ?string $host = null): TestResponse
{
    Livewire::flushState(); // a new request cycle, as in production: PersistentMiddleware remembers routes it already handled

    $uri = $host === null ? Livewire::getUpdateUri() : Hosts::url($host, Livewire::getUpdateUri());

    return postJson($uri, ['components' => [[
        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'updates' => $updates,
        'calls' => [['method' => $method, 'params' => $params, 'path' => '', 'metadata' => []]],
    ]]], ['X-Livewire' => '1']);
}

/**
 * Invites an address into an organisation with the organisation_admin template through InviteUser
 * (§4.5). Without an actor a fresh super admin invites; this helper signs nobody in.
 */
function inviteMember(Organisation $organisation, ?User $actor = null, string $email = 'invitee@example.com'): Invitation
{
    if (! $actor instanceof User) {
        $actor = User::factory()->superAdmin()->create();
        app(SetSuperAdmin::class)->handle($actor, true);
    }

    $templateId = (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

    return app(InviteUser::class)->handle($actor, $organisation, $email, [$templateId], Locale::default());
}

/** The signed accept link of an invitation, on its organisation's host (Invitation::acceptUrl()). */
function acceptLinkFor(Invitation $invitation): string
{
    return $invitation->acceptUrl();
}

/**
 * Turns the impersonation module on or off for the rest of the test (§6.8, RL-21): the flag, and the
 * module's routes as routes/web.php registers them at boot for that value. The CI job sqlite-free boots
 * the suite with MODULE_IMPERSONATION=false, so every test that needs the module on calls this first.
 * RouteCollection has no remove API, so "off" swaps in a copy without the impersonation.* routes.
 */
function useImpersonationModule(bool $enabled): void
{
    config(['skv.modules.impersonation' => $enabled]);

    $router = app(Router::class);

    if ($enabled && ! $router->getRoutes()->hasNamedRoute('impersonation.stop')) {
        Route::middleware('web')->group(base_path('routes/impersonation.php'));
    }

    if (! $enabled) {
        $kept = new RouteCollection;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'impersonation.')) {
                $kept->add($route);
            }
        }

        $router->setRoutes($kept);
    }

    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

/**
 * Turns the passkeys module on or off for the rest of the test (§6.8, RL-3): the flag, Fortify's passkeys
 * feature and the passkey routes, as they would be at boot for that value. The sqlite-free CI job boots the
 * suite with MODULE_PASSKEYS=false, so every test that needs passkeys calls this first. Fortify registers
 * its routes from one file, so "on" replays that file on a scratch router (through the Route facade) and
 * copies only the passkey routes; "off" swaps in a copy without them.
 */
function usePasskeysModule(bool $enabled): void
{
    config(['skv.modules.passkeys' => $enabled]);

    /** @var list<string> $features */
    $features = config('fortify.features', []);
    $withoutPasskeys = array_values(array_filter($features, fn (string $feature): bool => $feature !== Features::passkeys()));
    config(['fortify.features' => $enabled ? [...$withoutPasskeys, Features::passkeys(['confirmPassword' => true])] : $withoutPasskeys]);

    $router = app(Router::class);
    $isPasskeyRoute = fn (?string $name): bool => $name !== null && (str_starts_with($name, 'passkey.') || $name === 'well-known.passkeys');

    if ($enabled && ! $router->getRoutes()->hasNamedRoute('passkey.login-options')) {
        $scratch = new Router(app('events'), app());
        Route::swap($scratch);

        try {
            $scratch->group([
                'namespace' => 'Laravel\Fortify\Http\Controllers',
                'domain' => config('fortify.domain'),
                'prefix' => config('fortify.prefix'),
            ], base_path('vendor/laravel/fortify/routes/routes.php'));
            $scratch->middleware('web')->group(base_path('routes/passkeys.php'));
        } finally {
            Route::swap($router);
        }

        foreach ($scratch->getRoutes()->getRoutes() as $route) {
            if ($isPasskeyRoute($route->getName())) {
                $router->getRoutes()->add($route);
            }
        }

        RateLimiter::for('passkeys', FortifyServiceProvider::passkeysLimiter());
        Event::listen(TenantResolved::class, SetPasskeyOrigin::class);
        Event::listen(TenantForgotten::class, SetPasskeyOrigin::class);
    }

    if (! $enabled) {
        $kept = new RouteCollection;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! $isPasskeyRoute($route->getName())) {
                $kept->add($route);
            }
        }

        $router->setRoutes($kept);
    }

    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

/**
 * Signs $impersonator in through the login route on the current (tenant) host and starts impersonating
 * $target in a real web request, so the swap runs the whole web middleware stack (§4.7). The guard's
 * cached user is dropped before and after each request, as a new request in production would (RK-I9).
 */
function startImpersonatingOverHttp(User $impersonator, User $target, string $reason = 'Support ticket'): void
{
    Route::middleware(['web', 'auth'])->post('_start-impersonating', function () use ($target, $reason): string {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw new LogicException('Nobody is signed in.');
        }

        app(StartImpersonation::class)->handle($actor, $target, $reason);

        return 'impersonating';
    });

    Auth::guard('web')->forgetUser();
    post(route('login.store'), ['email' => $impersonator->email, 'password' => 'password'])->assertRedirect();
    Auth::guard('web')->forgetUser();
    post('/_start-impersonating')->assertOk();
    Auth::guard('web')->forgetUser();
}

/**
 * An active user for browser tests, password "password": a member of $organisation, or a super admin.
 * Signs nobody in: the browser signs in through the login page on the host it visits.
 */
function browserUser(?Organisation $organisation = null, string $email = 'member@example.com', bool $superAdmin = false): User
{
    if ($superAdmin) {
        $user = User::factory()->superAdmin()->create(['email' => $email]);
        app(SetSuperAdmin::class)->handle($user, true);

        return $user;
    }

    return User::factory()->for($organisation ?? Organisation::factory()->create())->create(['email' => $email]);
}

/**
 * The absolute URL of $path on the origin of $currentUrl (pass $page->url()). The browser plugin resolves a
 * relative navigate() path against 127.0.0.1, not the withHost() host, which drops the host-only session (RL-2).
 */
function sameOrigin(string $currentUrl, string $path): string
{
    return (string) Uri::of($currentUrl)->withPath($path)->withQuery([], merge: false)->withoutFragment();
}

/**
 * A throwaway project directory for setup steps (RL-2): .env from .env.example, storage and database folders.
 * Removed when the test ends; never the repository itself. A $prefix starts the folder name, e.g. setup_probe_
 * for the Database step's PostgreSQL databases, which take their name from the folder.
 */
function setupSandbox(?Detection $detection = null, string $prefix = ''): SetupContext
{
    $directory = storage_path('framework/testing/setup/'.$prefix.Str::uuid());

    foreach (['storage/app/private', 'database', 'public'] as $folder) {
        File::ensureDirectoryExists($directory.'/'.$folder);
    }

    File::copy(base_path('.env.example'), $directory.'/.env');

    // Register the clean-up on the running test case; Pest's test() proxy does not expose it to Larastan.
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('setupSandbox() runs inside a test only.');
    }

    Reflection::call($test, 'beforeApplicationDestroyed', [fn () => File::deleteDirectory($directory)]);

    $detection ??= Detection::none();

    return new SetupContext($directory, Answers::defaults($detection, basename($directory)), $detection);
}
