<?php

declare(strict_types=1);

use App\Actions\Invitations\InviteUser;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Hosts;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
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
 * Turns the impersonation module on or off for the rest of the test (§6.8, RL-21). The CI job sqlite-free
 * boots the suite with MODULE_IMPERSONATION=false, so every test that needs the module on calls this first.
 */
function useImpersonationModule(bool $enabled): void
{
    config(['skv.modules.impersonation' => $enabled]);
}
