<?php

declare(strict_types=1);

/** covers §4.6, §6.3, §7.2 */

use App\Authorization\Permission;
use Illuminate\Support\Str;

it('spells permission names only in the Permission enum', function (): void {
    $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), Permission::values()));
    $files = array_values(array_diff(kitSourceFiles('app', 'routes', 'resources/views'), ['app/Authorization/Permission.php']));

    // A name as a whole string, after a single-colon `prefix:` (can:, permission:) or inside a `|` / `,` list.
    // `pages::organisations.create` (a component name, double colon) is not a permission.
    $pattern = "/(['\"])(?:[^'\"]*(?<!:)[:|,])?(?:{$names})(?<!:)(?:[|,][^'\"]*)?\\1/";
    // Route names may equal a permission name (organisations.create): drop route-name arguments first.
    $routeNames = '/\b(?:name|route|to_route|redirectRoute|routeIs)\(\s*([\'"])[\w.*-]+\1/';
    $spells = fn (string $contents): bool => preg_match($pattern, (string) preg_replace($routeNames, '', $contents)) === 1;

    expect($spells("->can('organisations.create')"))->toBeTrue()
        ->and($spells("'can:organisations.create'"))->toBeTrue()
        ->and($spells("->name('organisations.create')"))->toBeFalse()
        ->and($spells("route('organisations.create')"))->toBeFalse()
        ->and($spells("'pages::organisations.create'"))->toBeFalse()
        ->and(array_values(array_filter($files, fn (string $path): bool => $spells((string) file_get_contents(base_path($path))))))->toBe([]);
});

it('changes the Spatie team id only in PermissionsTeam', function (): void {
    $files = array_values(array_diff(
        kitSourceFiles('app', 'routes', 'resources/views', 'database', 'tests'),
        ['app/Authorization/PermissionsTeam.php', 'tests/Arch/AuthorizationRulesTest.php'],
    ));

    expect(filesMatching($files, '/setPermissionsTeamId\s*\(/'))->toBe([]);
});

it('sets the team outside a restoring run() only in tenancy code', function (): void {
    $files = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'resources/views', 'database'),
        fn (string $path): bool => ! Str::startsWith($path, ['app/Tenancy/', 'app/Authorization/PermissionsTeam.php']),
    ));

    expect(filesMatching($files, '/PermissionsTeam::set\s*\(/'))->toBe([]);
});

arch('actions never depend on Livewire')
    ->expect('App\Actions')
    ->not->toUse(['Livewire', 'App\Livewire']);
