<?php

declare(strict_types=1);

/** covers §4.6, §6.3, §7.2 */

use App\Authorization\Permission;

it('spells permission names only in the Permission enum', function (): void {
    $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), Permission::values()));
    $files = array_values(array_diff(kitSourceFiles('app', 'routes', 'resources/views'), ['app/Authorization/Permission.php']));

    expect(filesMatching($files, "/(['\"])({$names})\\1/"))->toBe([]);
});

it('changes the Spatie team id only in PermissionsTeam', function (): void {
    $files = array_values(array_diff(
        kitSourceFiles('app', 'routes', 'resources/views', 'database', 'tests'),
        ['app/Authorization/PermissionsTeam.php', 'tests/Arch/AuthorizationRulesTest.php'],
    ));

    expect(filesMatching($files, '/setPermissionsTeamId\s*\(/'))->toBe([]);
});

arch('actions never depend on Livewire')
    ->expect('App\Actions')
    ->not->toUse('Livewire');
