<?php

declare(strict_types=1);

use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Hosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

use function Pest\Laravel\actingAs;

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
