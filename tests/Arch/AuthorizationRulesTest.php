<?php

declare(strict_types=1);

/** covers §4.6, §6.3, §7.2 */

use App\Authorization\Permission;
use Illuminate\Support\Str;

it('spells permission names only in the Permission enum', function (): void {
    $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), Permission::values()));
    $files = array_values(array_diff(kitSourceFiles('app', 'routes', 'resources/views'), ['app/Authorization/Permission.php']));

    // A name as a whole string, after a `prefix:` (can:, permission:) or inside a `|` / `,` list.
    $pattern = "/(['\"])(?:[^'\"]*[:|,])?(?:{$names})(?:[|,][^'\"]*)?\\1/";

    expect(filesMatching($files, $pattern))->toBe([]);
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
