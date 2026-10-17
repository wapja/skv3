<?php

declare(strict_types=1);

/** covers §4.6.8, §6.3, §7.2 */

use App\Actions\Users\UpdateSuperAdmin;
use App\Authorization\Permission;
use App\Authorization\SetSuperAdmin;
use App\Console\Commands\CreateSuperAdminCommand;
use App\Setup\Steps\DatabaseStep;
use Database\Seeders\DemoSeeder;
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

// L7b-39: the super-admin flag changes only through SetSuperAdmin, and SetSuperAdmin is called only by the action pages
// use (UpdateSuperAdmin, which authorises first), setup's database step, skv:create-super-admin and the demo seeder.
arch('only the super-admin action, setup, the CLI and the demo seeder call SetSuperAdmin (L7b-39)')
    ->expect(SetSuperAdmin::class)
    ->toOnlyBeUsedIn([UpdateSuperAdmin::class, DatabaseStep::class, CreateSuperAdminCommand::class, DemoSeeder::class]);

it('never calls SetSuperAdmin from a page, a component, a route file, the bootstrap, the config or a migration (L7b-39)', function (): void {
    // The arch rule sees autoloaded classes only; Livewire's multi-file classes, route closures, bootstrap/app.php's
    // callbacks, config files and anonymous migration classes are scanned as text.
    $files = array_values(array_filter(
        kitSourceFiles('resources/views', 'routes', 'bootstrap', 'config', 'database/migrations'),
        fn (string $path): bool => ! str_starts_with($path, 'bootstrap/cache/'),
    ));

    expect(filesMatching($files, '/\bSetSuperAdmin\b/'))->toBe([]);
});

it('writes users.is_super_admin only in SetSuperAdmin (§6.3, M7c T13 #6)', function (): void {
    // L7b-39 pins who calls SetSuperAdmin; this pins that nothing else writes the flag: no `true` for it, no property
    // assignment, and no value other than `false` in a write (InviteUser creates invitees with false). Factories are
    // test data and exempt.
    $pattern = '/[\'"]is_super_admin[\'"]\s*=>\s*(?:true|1)\b'
        .'|->is_super_admin\s*=(?!=)'
        .'|\b(?:update|forceFill|fill|insert|insertGetId|insertOrIgnore|upsert|updateOrInsert|updateOrCreate|firstOrCreate|forceCreate)\s*\([^;]*?[\'"]is_super_admin[\'"]\s*=>\s*+(?!false\b)'
        .'|setAttribute\s*\(\s*[\'"]is_super_admin[\'"]\s*,\s*+(?!false\b)/s';

    foreach ([
        '$user->forceFill([\'is_super_admin\' => true])->save();',
        'DB::table(\'users\')->where(\'id\', $id)->update([\'is_super_admin\' => $flag]);',
        "User::query()->whereKey(\$id)->update([\n    'name' => \$name,\n    'is_super_admin' => \$flag,\n]);",
        '$user->is_super_admin = true;',
        '$user->setAttribute(\'is_super_admin\', $flag);',
        '$user->fill([\'is_super_admin\' => 1]);',
        'User::forceCreate([\'email\' => $email, \'is_super_admin\' => $flag]);',
    ] as $form) {
        expect(preg_match($pattern, $form))->toBe(1, "The super-admin write rule misses [{$form}].");
    }

    foreach ([
        '$invitee->forceFill([\'organisation_id\' => $id, \'is_super_admin\' => false, \'status\' => $status])->save();',
        'return $this->is_super_admin === true;',
        'User::query()->where(\'is_super_admin\', true)->exists();',
        '\'is_super_admin\' => __(\'Cancel the invitation and invite again with the new roles.\'),',
        '->lockForUpdate()->get([\'id\', \'email\', \'is_super_admin\']);',
        '$table->boolean(\'is_super_admin\')->default(false)->index();',
        '\'is_super_admin\' => \'boolean\',',
    ] as $form) {
        expect(preg_match($pattern, $form))->toBe(0, "The super-admin write rule flags [{$form}].");
    }

    $files = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'resources/views'),
        fn (string $path): bool => $path !== 'app/Authorization/SetSuperAdmin.php' && ! str_starts_with($path, 'bootstrap/cache/'),
    ));

    expect(filesMatching($files, $pattern))->toBe([]);
});
